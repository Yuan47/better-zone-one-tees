<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\User;
use App\Models\Variant;
use App\Services\ActivityRecorder;
use App\Services\InvoicePayments;
use App\Services\InvoiceTerms;
use App\Services\OrderFlow;
use App\Services\PayMongo;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class WalkInController extends Controller
{
    public function create(Request $request, PayMongo $pay)
    {
        $this->authorizeStaff($request);
        $variants = Variant::with('product')->whereHas('product', fn ($query) => $query->where('active', true))
            ->orderBy('product_id')->orderBy('id')->get();
        $choices = $variants->map(fn ($variant) => ['id' => $variant->id,
            'label' => $variant->product->name.' · '.$variant->color.' · '.$variant->size,
            'available' => $variant->available(), 'retail' => (int) $variant->retail_price,
            'wholesale' => (int) $variant->wholesale_price, 'reseller' => (int) $variant->reseller_price])->values();
        $customers = User::where('role', 'customer')->orderBy('name')->get(['id', 'name']);

        return view('admin.walk-in', ['variants' => $choices, 'customers' => $customers, 'payReady' => $pay->ready(),
            'requestKey' => (string) Str::uuid(), 'minimumSubtotal' => app(InvoiceTerms::class)->minimumSubtotal()]);
    }

    public function store(Request $request, OrderFlow $flow, InvoicePayments $payments, PayMongo $pay)
    {
        $this->authorizeStaff($request);
        $key = strtolower($request->validate(['request_key' => 'required|uuid'])['request_key']);
        if ($existing = Order::where('creation_key', $key)->first()) {
            return $this->existing($existing);
        }
        $data = $request->validate([
            'customer_type' => 'required|in:guest,registered',
            'customer_id' => ['exclude_unless:customer_type,registered', 'required', 'integer', Rule::exists('users', 'id')->where('role', 'customer')],
            'recipient' => 'required_if:customer_type,guest|nullable|string|max:100',
            'phone' => ['nullable', 'regex:/^\+?[0-9]{10,15}$/D'],
            'contact_email' => 'nullable|email|max:254',
            'lines' => 'required|array|min:1|max:100', 'lines.*.variant_id' => 'required|integer|exists:variants,id',
            'lines.*.quantity' => 'required|integer|min:1|max:10000',
            'payment_option' => 'required|in:full_cash,full_paymongo,partial_cash,partial_paymongo',
            'amount' => ['exclude_unless:payment_option,partial_cash,partial_paymongo', 'required', 'regex:/^\d{1,8}(?:\.\d{1,2})?$/D', 'numeric', 'min:1'],
            'due_at' => 'exclude_unless:payment_option,partial_cash,partial_paymongo|required|date_format:Y-m-d|after_or_equal:'.now()->addDays(7)->toDateString().'|before_or_equal:'.now()->addDays(30)->toDateString(),
            'reason' => 'required_if:payment_option,full_cash,partial_cash|nullable|string|min:10|max:1000',
            'payment_received' => 'exclude_unless:payment_option,full_cash,partial_cash|required|accepted',
        ]);
        $online = str_ends_with($data['payment_option'], '_paymongo');
        $partial = str_starts_with($data['payment_option'], 'partial_');
        if ($online && ! $pay->ready()) {
            throw ValidationException::withMessages(['payment' => 'Online payments are unavailable. Use cash or ask the owner to connect PayMongo.']);
        }
        $customer = $data['customer_type'] === 'registered' ? User::where('role', 'customer')->findOrFail($data['customer_id']) : null;
        $phone = $data['phone'] ?? $customer?->phone ?? '';
        if ($online && ! preg_match('/^\+?[0-9]{10,15}$/D', $phone)) {
            throw ValidationException::withMessages(['phone' => 'A valid phone number is required for PayMongo checkout.']);
        }
        $cart = [];
        foreach ($data['lines'] as $line) {
            $id = (int) $line['variant_id'];
            $cart[$id] = ($cart[$id] ?? 0) + (int) $line['quantity'];
            if ($cart[$id] > 10000) {
                throw ValidationException::withMessages(['lines' => 'A variant can contain up to 10,000 pieces per invoice.']);
            }
        }
        try {
            [$order, $created] = DB::transaction(function () use ($request, $flow, $payments, $data, $key, $customer, $phone, $cart, $partial, $online) {
                if ($existing = Order::where('creation_key', $key)->lockForUpdate()->first()) {
                    return [$existing, false];
                }
                $order = $flow->reserve($cart, ['creation_key' => $key, 'created_by' => $request->user()->id, 'sales_channel' => 'walk_in',
                    'payment_plan' => $partial ? 'partial' : 'full', 'due_at' => $partial ? CarbonImmutable::parse($data['due_at'])->endOfDay() : null,
                    'recipient' => $customer?->name ?? $data['recipient'], 'phone' => $phone, 'contact_email' => $customer?->email ?? ($data['contact_email'] ?? null),
                    'address' => 'Store pickup', 'delivery_method' => 'pickup', 'shipping' => 0], $customer?->id);
                if ($partial) {
                    app(InvoiceTerms::class)->validatePartial($order);
                }
                $amount = $partial ? InvoiceTerms::amount($data['amount']) : (int) $order->total;
                if ($partial && $amount >= $order->total) {
                    throw ValidationException::withMessages(['amount' => 'A partial payment must be less than the invoice total. Choose full payment to pay the whole invoice.']);
                }
                if (! $online) {
                    $payments->recordCash($order, $amount, $request->user(), $data['reason'], $key);
                }

                return [$order->fresh(), true];
            }, 3);
        } catch (UniqueConstraintViolationException $exception) {
            $order = Order::where('creation_key', $key)->first();
            if (! $order) {
                throw $exception;
            }

            return $this->existing($order);
        }
        if (! $created) {
            return $this->existing($order);
        }
        ActivityRecorder::mark($request, 'order.created', $order, ['quantity' => $order->quantity, 'delivery_method' => 'pickup', 'status' => $order->status]);
        if ($online) {
            try {
                $amount = $partial ? InvoiceTerms::amount($data['amount']) : (int) $order->total;
                $attempt = $payments->start($order, $amount, $request->user(), $key);
                if (is_string($attempt->checkout_url) && str_starts_with($attempt->checkout_url, 'https://checkout.paymongo.com/')) {
                    return redirect()->away($attempt->checkout_url);
                }
            } catch (\Throwable $exception) {
                // The saved invoice must survive an uncertain provider outcome.
            }

            return redirect()->route('orders.show', $order)->withErrors(['payment' => 'The invoice was saved. Check its payment status before creating another checkout.']);
        }

        return redirect()->route('orders.show', $order)->with('notice', 'Walk-in invoice saved and cash payment recorded.');
    }

    private function existing(Order $order)
    {
        if ($order->sales_channel !== 'walk_in') {
            throw ValidationException::withMessages(['request_key' => 'This checkout reference is already in use. Open a new walk-in checkout.']);
        }

        return redirect()->route('orders.show', $order)->with('notice', 'This walk-in checkout was already saved. The existing invoice is shown.');
    }

    private function authorizeStaff(Request $request): void
    {
        abort_unless(in_array($request->user()?->role, ['staff', 'admin'], true), 403);
    }
}
