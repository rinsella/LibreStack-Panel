@extends('layouts.guest')
@section('title', 'Sign in')

@section('content')
<div class="rounded-2xl bg-white p-8 shadow-xl">
    <h2 class="mb-6 text-center text-xl font-bold text-slate-900">Sign in to your panel</h2>

    <form method="POST" action="{{ route('login.store') }}" class="space-y-4">
        @csrf
        <div>
            <label class="ls-label" for="email">Email address</label>
            <input class="ls-input" id="email" name="email" type="email" value="{{ old('email') }}" required autofocus />
        </div>
        <div>
            <label class="ls-label" for="password">Password</label>
            <input class="ls-input" id="password" name="password" type="password" required />
        </div>

        @error('email')
            <p class="text-sm text-red-600">{{ $message }}</p>
        @enderror

        <div class="flex items-center justify-between">
            <label class="flex items-center gap-2 text-sm text-slate-600">
                <input type="checkbox" name="remember" class="rounded border-slate-300 text-brand-600 focus:ring-brand-500" />
                Remember me
            </label>
        </div>

        <button type="submit" class="ls-btn ls-btn-primary w-full justify-center">Sign in</button>
    </form>
</div>
@endsection
