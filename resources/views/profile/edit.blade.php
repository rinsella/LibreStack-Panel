@extends('layouts.app')
@section('title', 'My account')
@section('subheading', 'Update your profile information and password.')

@section('content')
<div class="grid gap-6 lg:grid-cols-2">
    <x-card title="Profile" subtitle="Your name and email address.">
        <form method="POST" action="{{ route('profile.update') }}" class="space-y-4">
            @csrf
            @method('PUT')
            <div>
                <label class="ls-label" for="name">Name</label>
                <input class="ls-input" id="name" name="name" value="{{ old('name', $user->name) }}" required />
            </div>
            <div>
                <label class="ls-label" for="email">Email</label>
                <input class="ls-input" id="email" name="email" type="email" value="{{ old('email', $user->email) }}" required />
            </div>
            <div class="flex justify-end">
                <button class="ls-btn ls-btn-primary">Save profile</button>
            </div>
        </form>
    </x-card>

    <x-card title="Password" subtitle="Choose a strong, unique password.">
        <form method="POST" action="{{ route('password.update') }}" class="space-y-4">
            @csrf
            @method('PUT')
            <div>
                <label class="ls-label" for="current_password">Current password</label>
                <input class="ls-input" id="current_password" name="current_password" type="password" required />
            </div>
            <div>
                <label class="ls-label" for="password">New password</label>
                <input class="ls-input" id="password" name="password" type="password" required />
            </div>
            <div>
                <label class="ls-label" for="password_confirmation">Confirm new password</label>
                <input class="ls-input" id="password_confirmation" name="password_confirmation" type="password" required />
            </div>
            <div class="flex justify-end">
                <button class="ls-btn ls-btn-primary">Change password</button>
            </div>
        </form>
    </x-card>

    <x-card title="Two-factor authentication" subtitle="Add a second layer of security.">
        <div class="flex items-center justify-between">
            <p class="text-sm text-slate-500">TOTP-based 2FA is on the roadmap.</p>
            <x-badge status="pending">Coming soon</x-badge>
        </div>
    </x-card>

    <x-card title="Roles" subtitle="Permissions granted to your account.">
        <div class="flex flex-wrap gap-2">
            @forelse ($user->roles as $role)
                <x-badge status="active">{{ $role->label }}</x-badge>
            @empty
                <p class="text-sm text-slate-500">No roles assigned.</p>
            @endforelse
        </div>
    </x-card>
</div>
@endsection
