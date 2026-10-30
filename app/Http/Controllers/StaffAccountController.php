<?php

namespace App\Http\Controllers;

use App\Models\StaffInvitation;
use App\Models\User;
use App\Services\StaffAccounts;
use App\Support\WorkspaceTableSort;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class StaffAccountController extends Controller
{
    public function index(Request $request, StaffAccounts $accounts)
    {
        $this->authorizeAdmin($request);

        $staff = WorkspaceTableSort::apply(User::where('role', 'staff'), $request, [
            'name' => 'users.name', 'email' => 'users.email', 'verified' => "CASE WHEN users.email_verified_at IS NULL THEN 'Not yet verified' ELSE 'Verified' END", 'created' => 'users.created_at',
        ], fn ($query) => $query->orderBy('name'), 'staff_')->paginate(15, ['*'], 'staff_page')->withQueryString();
        $invitations = WorkspaceTableSort::apply(StaffInvitation::whereNull('consumed_at'), $request, [
            'name' => 'staff_invitations.name', 'email' => 'staff_invitations.email', 'created' => 'staff_invitations.created_at',
        ], fn ($query) => $query->latest(), 'setup_')->paginate(15, ['*'], 'setup_page')->withQueryString();

        return $this->privateView('admin.staff.index', [
            'staff' => $staff, 'invitations' => $invitations,
            'activeCount' => User::where('role', 'staff')->count(),
            'pendingCount' => StaffInvitation::whereNull('consumed_at')->count(),
            'setupReady' => $accounts->ready(),
        ]);
    }

    public function create(Request $request, StaffAccounts $accounts)
    {
        $this->authorizeAdmin($request);

        return $this->privateView('admin.staff.create', ['setupReady' => $accounts->ready()]);
    }

    public function store(Request $request, StaffAccounts $accounts)
    {
        $this->authorizeAdmin($request);
        try {
            $invitation = $accounts->create($request->user(), $request->only(['name', 'email', 'password', 'password_confirmation']));
        } catch (ValidationException $exception) {
            // Initial passwords are never copied into session old input.
            return redirect()->route('admin.staff.create')->withErrors($exception->errors())->withInput($request->only(['name', 'email']));
        }
        $status = $accounts->send($request->user(), $invitation);

        return redirect()->route('admin.staff.show', $invitation)->with('notice', 'Staff setup saved. '.self::sendMessage($status));
    }

    public function show(Request $request, StaffInvitation $invitation, StaffAccounts $accounts)
    {
        $this->authorizeAdmin($request);

        return $this->privateView('admin.staff.show', ['invitation' => $invitation, 'setup' => $accounts->state($invitation),
            'setupReady' => $accounts->ready()]);
    }

    public function send(Request $request, StaffInvitation $invitation, StaffAccounts $accounts)
    {
        $this->authorizeAdmin($request);

        return redirect()->route('admin.staff.show', $invitation)->with('notice', self::sendMessage($accounts->send($request->user(), $invitation)));
    }

    public function verify(Request $request, StaffInvitation $invitation, StaffAccounts $accounts)
    {
        $this->authorizeAdmin($request);
        $code = $request->input('code');
        if (! is_string($code)) {
            return redirect()->route('admin.staff.show', $invitation)->withErrors(['code' => 'Enter the six-digit code received by the staff member.']);
        }
        try {
            $accounts->verify($request->user(), $invitation, $code);
        } catch (ValidationException $exception) {
            // OTPs are never copied into session old input, including malformed guesses.
            return redirect()->route('admin.staff.show', $invitation)->withErrors($exception->errors());
        }

        return redirect()->route('admin.staff.show', $invitation)->with('notice', 'Staff account activated. They can now sign in with their email and the initial password you set.');
    }

    private function authorizeAdmin(Request $request): void
    {
        abort_unless($request->user() && User::whereKey($request->user()->id)->where('role', 'admin')->exists(), 403);
    }

    private function privateView(string $view, array $data)
    {
        return response()->view($view, $data)->header('Cache-Control', 'no-store, private')->header('Referrer-Policy', 'same-origin');
    }

    private static function sendMessage(string $status): string
    {
        return match ($status) {
            'sent' => 'A setup code was accepted for sending to the staff member’s email. Ask them for this staff setup code, then enter it here.',
            'unknown' => 'Email sending has not been confirmed. If the staff member receives a code, enter it here. Wait before requesting another.',
            'cooldown' => 'Please wait before requesting another setup code.',
            'limited' => 'The hourly limit of five setup emails has been reached. Please try again later.',
            'activated' => 'This staff account is already activated.',
            'email_taken' => 'This email already has an account. It cannot be changed into a staff account through this setup.',
            'failed' => 'The setup email could not be sent. Wait before retrying or check the store’s email settings.',
            default => 'Email delivery is not configured yet. This setup remains pending and cannot sign in until verified.',
        };
    }
}
