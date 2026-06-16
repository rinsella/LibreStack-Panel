<?php

namespace App\Http\Controllers;

use App\Models\Role;
use App\Models\User;
use App\Support\Audit;
use App\Support\Validators;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

class UserController extends Controller
{
    public function index(Request $request)
    {
        $search = (string) $request->query('q', '');

        $users = User::with('roles')
            ->when($search !== '', fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%"))
            ->latest()
            ->paginate(15)
            ->withQueryString();

        return view('users.index', compact('users', 'search'));
    }

    public function create()
    {
        return view('users.create', [
            'user'  => new User(),
            'roles' => Role::orderBy('label')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name'            => ['required', 'string', 'max:255'],
            'email'           => ['required', 'email', 'max:255', 'unique:users,email'],
            'password'        => ['required', 'confirmed', Password::min(10)->mixedCase()->numbers()],
            'status'          => ['required', 'in:active,suspended'],
            'system_username' => ['nullable', 'string', 'max:32'],
            'roles'           => ['array'],
            'roles.*'         => ['exists:roles,id'],
        ]);

        if (! empty($data['system_username']) && ! Validators::isValidUsername($data['system_username'])) {
            return back()->withInput()->withErrors(['system_username' => 'Invalid system username.']);
        }

        $user = User::create([
            'name'            => $data['name'],
            'email'           => $data['email'],
            'password'        => Hash::make($data['password']),
            'status'          => $data['status'],
            'system_username' => $data['system_username'] ?? null,
        ]);

        $user->roles()->sync($data['roles'] ?? []);

        Audit::log('user.created', 'user', (string) $user->id, ['email' => $user->email]);

        return redirect()->route('users.index')->with('success', 'User created.');
    }

    public function edit(User $user)
    {
        return view('users.edit', [
            'user'  => $user->load('roles'),
            'roles' => Role::orderBy('label')->get(),
        ]);
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate([
            'name'            => ['required', 'string', 'max:255'],
            'email'           => ['required', 'email', 'max:255', 'unique:users,email,' . $user->id],
            'password'        => ['nullable', 'confirmed', Password::min(10)->mixedCase()->numbers()],
            'status'          => ['required', 'in:active,suspended'],
            'system_username' => ['nullable', 'string', 'max:32'],
            'roles'           => ['array'],
            'roles.*'         => ['exists:roles,id'],
        ]);

        $user->update([
            'name'            => $data['name'],
            'email'           => $data['email'],
            'status'          => $data['status'],
            'system_username' => $data['system_username'] ?? null,
        ]);

        if (! empty($data['password'])) {
            $user->update(['password' => Hash::make($data['password'])]);
        }

        $user->roles()->sync($data['roles'] ?? []);

        Audit::log('user.updated', 'user', (string) $user->id, ['email' => $user->email]);

        return redirect()->route('users.index')->with('success', 'User updated.');
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        if ($user->id === $request->user()->id) {
            return back()->with('error', 'You cannot delete your own account.');
        }

        if ($user->isSuperAdmin() && User::whereHas('roles', fn ($q) => $q->where('name', 'super_admin'))->count() <= 1) {
            return back()->with('error', 'Cannot delete the last super admin.');
        }

        $email = $user->email;
        $user->delete();

        Audit::log('user.deleted', 'user', (string) $user->id, ['email' => $email]);

        return back()->with('success', 'User deleted.');
    }
}
