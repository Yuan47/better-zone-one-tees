<?php

namespace App\Services;

use App\Models\CustomerAddress;
use App\Models\User;
use App\Rules\PhilippineMobileNumber;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class CustomerAddresses
{
    public function forUser(User $user)
    {
        abort_unless($user->role === 'customer', 403);

        return CustomerAddress::where('user_id', $user->id)->orderByDesc('is_default')->orderBy('id')->get();
    }

    public function localPhone(?string $phone): ?string
    {
        if (preg_match('/^\+639([0-9]{9})$/D', (string) $phone, $matches)) {
            return '09'.$matches[1];
        }

        return $phone;
    }

    public function save(User $user, array $data, ?CustomerAddress $address = null): CustomerAddress
    {
        return DB::transaction(function () use ($user, $data, $address) {
            User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            if ($address) {
                $address = CustomerAddress::where('user_id', $user->id)->lockForUpdate()->findOrFail($address->id);
            } else {
                $address = new CustomerAddress;
                $address->user_id = $user->id;
            }
            $default = (bool) ($data['is_default'] ?? false)
                || ! CustomerAddress::where('user_id', $user->id)->exists()
                || $address->is_default;
            if ($default) {
                CustomerAddress::where('user_id', $user->id)
                    ->when($address->exists, fn ($query) => $query->where('id', '<>', $address->id))
                    ->update(['is_default' => false]);
            }
            $address->fill($data);
            $address->is_default = $default;
            $address->save();

            return $address;
        }, 3);
    }

    public function makeDefault(User $user, CustomerAddress $address): void
    {
        DB::transaction(function () use ($user, $address) {
            User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $owned = CustomerAddress::where('user_id', $user->id)->lockForUpdate()->findOrFail($address->id);
            CustomerAddress::where('user_id', $user->id)->where('id', '<>', $owned->id)->update(['is_default' => false]);
            $owned->update(['is_default' => true]);
        }, 3);
    }

    public function delete(User $user, CustomerAddress $address): void
    {
        DB::transaction(function () use ($user, $address) {
            User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $owned = CustomerAddress::where('user_id', $user->id)->lockForUpdate()->findOrFail($address->id);
            $wasDefault = $owned->is_default;
            $owned->delete();
            if ($wasDefault) {
                CustomerAddress::where('user_id', $user->id)->orderBy('id')->first()?->update(['is_default' => true]);
            }
        }, 3);
    }

    public function applySelected(Request $request): void
    {
        if (! $request->filled('address_id')) {
            return;
        }
        abort_unless($request->user()?->role === 'customer', 403);
        $request->validate(['address_id' => ['required', 'integer']]);
        $address = CustomerAddress::where('user_id', $request->user()->id)->findOrFail($request->integer('address_id'));
        $values = $address->only(['recipient', 'phone', ...StructuredAddress::FIELDS, 'lat', 'lng']);
        $values['phone'] = $this->localPhone($values['phone']);
        Validator::make($values, [
            'recipient' => ['required', 'string', 'max:100'],
            'phone' => ['required', new PhilippineMobileNumber],
            ...app(StructuredAddress::class)->rules(),
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
        ], ['lat.required' => 'Edit this saved address and select its delivery pin first.',
            'lng.required' => 'Edit this saved address and select its delivery pin first.'])->validate();
        $request->merge($values);
    }
}
