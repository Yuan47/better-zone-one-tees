<?php

namespace App\Http\Controllers;

use App\Models\CustomerAddress;
use App\Rules\PhilippineMobileNumber;
use App\Services\ActivityRecorder;
use App\Services\CustomerAddresses;
use App\Services\StructuredAddress;
use Illuminate\Http\Request;

class CustomerAddressController extends Controller
{
    public function index(Request $request, CustomerAddresses $addresses)
    {
        abort_unless($request->user()->role === 'customer', 403);

        return view('addresses.index', ['user' => $request->user(), 'addresses' => $addresses->forUser($request->user())]);
    }

    public function create(Request $request, CustomerAddresses $addresses)
    {
        abort_unless($request->user()->role === 'customer', 403);
        $user = $request->user();
        $address = new CustomerAddress(['label' => 'Home', 'recipient' => $user->name,
            'phone' => $addresses->localPhone($user->phone), 'country' => 'Philippines']);

        return view('addresses.form', compact('user', 'address'));
    }

    public function edit(Request $request, CustomerAddress $address, CustomerAddresses $addresses)
    {
        $this->authorizeAddress($request, $address);
        $address->phone = $addresses->localPhone($address->phone);

        return view('addresses.form', ['user' => $request->user(), 'address' => $address]);
    }

    public function store(Request $request, CustomerAddresses $addresses)
    {
        abort_unless($request->user()->role === 'customer', 403);
        $address = $addresses->save($request->user(), $this->validated($request));
        ActivityRecorder::mark($request, 'address.created', $address);
        $request->session()->forget('delivery_quote');

        return redirect()->route('account.addresses.index')->with('notice', 'Delivery address saved.');
    }

    public function update(Request $request, CustomerAddress $address, CustomerAddresses $addresses)
    {
        $this->authorizeAddress($request, $address);
        $addresses->save($request->user(), $this->validated($request), $address);
        ActivityRecorder::mark($request, 'address.updated', $address);
        $request->session()->forget('delivery_quote');

        return redirect()->route('account.addresses.index')->with('notice', 'Delivery address updated.');
    }

    public function default(Request $request, CustomerAddress $address, CustomerAddresses $addresses)
    {
        $this->authorizeAddress($request, $address);
        $addresses->makeDefault($request->user(), $address);
        ActivityRecorder::mark($request, 'address.default_changed', $address);
        $request->session()->forget('delivery_quote');

        return redirect()->route('account.addresses.index')->with('notice', 'Default delivery address updated.');
    }

    public function destroy(Request $request, CustomerAddress $address, CustomerAddresses $addresses)
    {
        $this->authorizeAddress($request, $address);
        $addresses->delete($request->user(), $address);
        ActivityRecorder::mark($request, 'address.deleted', $address);
        $request->session()->forget('delivery_quote');

        return redirect()->route('account.addresses.index')->with('notice', 'Saved address removed.');
    }

    private function authorizeAddress(Request $request, CustomerAddress $address): void
    {
        abort_unless($request->user()->role === 'customer' && $address->user_id === $request->user()->id, 404);
    }

    private function validated(Request $request): array
    {
        $rules = app(StructuredAddress::class)->rules();
        $data = $request->validate([
            'label' => ['required', 'string', 'max:40'],
            'recipient' => ['required', 'string', 'max:100'],
            'phone' => ['required', new PhilippineMobileNumber],
            ...$rules,
            'country' => ['required', 'in:Philippines'],
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
            'pin_confirmed' => ['accepted'],
            'is_default' => ['sometimes', 'boolean'],
        ], ['pin_confirmed.accepted' => 'Confirm that the pin marks your delivery entrance.']);
        unset($data['pin_confirmed']);
        foreach (['label', 'recipient', ...StructuredAddress::FIELDS] as $field) {
            $data[$field] = trim($data[$field]);
        }

        return $data;
    }
}
