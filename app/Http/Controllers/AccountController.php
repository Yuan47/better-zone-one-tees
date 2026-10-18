<?php

namespace App\Http\Controllers;

use App\Rules\AccountPasswordSize;
use App\Services\ActivityRecorder;
use App\Services\ImageUploads;
use App\Services\StructuredAddress;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password as PasswordBroker;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class AccountController extends Controller
{
    public function show(Request $request)
    {
        return view('account', ['user' => $request->user()]);
    }

    public function update(Request $request, ImageUploads $images, StructuredAddress $addresses)
    {
        $user = $request->user();
        if ($request->has('username')) {
            $request->merge(['username' => Str::lower(trim((string) $request->input('username')))]);
        }
        $rules = [
            'name' => ['required', 'string', 'max:100'],
            'username' => ['required', 'string', 'min:3', 'max:40', 'regex:/^[a-z0-9_.-]+$/', Rule::unique('users', 'username')->ignore($user->id)],
            'avatar' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048', 'dimensions:max_width=4096,max_height=4096'],
            'remove_avatar' => ['sometimes', 'boolean'],
            'email' => ['prohibited'],
            'role' => ['prohibited'],
            'avatar_path' => ['prohibited'],
            'password' => ['prohibited'],
        ];
        if ($user->role === 'customer') {
            $rules['phone'] = ['nullable', 'regex:/^\+?[0-9]{10,15}$/'];
        } else {
            foreach ([...StructuredAddress::FIELDS, 'phone', 'lat', 'lng'] as $field) {
                $rules[$field] = ['prohibited'];
            }
        }
        $data = $request->validate($rules);
        $changes = ['name' => trim($data['name']), 'username' => $data['username']];
        if ($user->role === 'customer') {
            $changes += $addresses->validatedInput($request, false);
            $changes['phone'] = $data['phone'] ?? null;
        }
        $newAvatar = null;
        $oldAvatar = $user->avatar_path;
        if ($request->hasFile('avatar')) {
            $newAvatar = $images->store($request->file('avatar'), 'avatars');
            $changes['avatar_path'] = $newAvatar;
        } elseif ($request->boolean('remove_avatar')) {
            $changes['avatar_path'] = null;
        }
        try {
            $user->update($changes);
        } catch (\Throwable $exception) {
            $images->delete($newAvatar);
            throw $exception;
        }
        if (array_key_exists('avatar_path', $changes) && $oldAvatar !== $changes['avatar_path']) {
            $images->delete($oldAvatar);
        }
        $changedFields = array_values(array_diff(array_keys($user->getChanges()), ['updated_at']));
        if ($changedFields) {
            ActivityRecorder::mark($request, 'account.updated', $user, ['fields' => $changedFields]);
        }

        return redirect()->route('account')->with('notice', 'Your account has been updated.');
    }

    public function password(Request $request)
    {
        $data = $request->validate([
            'current_password' => ['required', 'string', 'current_password:web'],
            'password' => ['required', 'string', 'confirmed', 'different:current_password', Password::min(10), new AccountPasswordSize],
        ]);
        $user = $request->user();
        DB::transaction(function () use ($user, $data, $request) {
            // Recovery and signed-in password changes serialize on the same account.
            $locked = $user->newQuery()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            if (! Hash::check($data['current_password'], $locked->password)) {
                throw ValidationException::withMessages(['current_password' => 'The current password is incorrect.']);
            }
            $locked->update(['password' => $data['password'], 'remember_token' => Str::random(60)]);
            PasswordBroker::broker('users')->deleteToken($locked);
            DB::table('sessions')->where('user_id', $user->id)->where('id', '!=', $request->session()->getId())->delete();
        });
        $user->refresh();
        $request->session()->regenerate(true);
        $request->session()->regenerateToken();
        $request->session()->put('password_hash_web', $user->password);
        ActivityRecorder::mark($request, 'account.password_changed', $user);

        return redirect()->route('account')->with('notice', 'Password changed. Other signed-in browser sessions have been signed out.');
    }
}
