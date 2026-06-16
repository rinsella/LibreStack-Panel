@extends('layouts.guest')
@section('title', 'First-run setup')

@section('content')
<div class="rounded-2xl bg-white p-8 shadow-xl">
    <div class="mb-6 text-center">
        <h2 class="text-xl font-bold text-slate-900">Create your admin account</h2>
        <p class="mt-1 text-sm text-slate-500">This is the first and only step. No default password is ever created.</p>
    </div>

    <div class="mb-6 rounded-xl border border-slate-200 bg-slate-50 p-4">
        <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-400">System requirements</p>
        <ul class="space-y-1.5">
            @foreach ($requirements as $req)
                <li class="flex items-center gap-2 text-sm {{ $req['ok'] ? 'text-slate-700' : 'text-red-600' }}">
                    <span class="flex h-4 w-4 items-center justify-center rounded-full {{ $req['ok'] ? 'bg-emerald-100 text-emerald-600' : 'bg-red-100 text-red-600' }}">
                        @include('partials.icons', ['name' => $req['ok'] ? 'check' : 'x', 'class' => 'h-3 w-3'])
                    </span>
                    {{ $req['label'] }}
                </li>
            @endforeach
        </ul>
    </div>

    <form method="POST" action="{{ route('setup.store') }}" class="space-y-4">
        @csrf
        <div>
            <label class="ls-label" for="panel_name">Panel name</label>
            <input class="ls-input" id="panel_name" name="panel_name" value="{{ old('panel_name', 'LibreStack Panel') }}" />
        </div>
        <div>
            <label class="ls-label" for="name">Your name</label>
            <input class="ls-input" id="name" name="name" value="{{ old('name') }}" required autofocus />
        </div>
        <div>
            <label class="ls-label" for="email">Email address</label>
            <input class="ls-input" id="email" name="email" type="email" value="{{ old('email') }}" required />
        </div>
        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <label class="ls-label" for="password">Password</label>
                <input class="ls-input" id="password" name="password" type="password" required />
            </div>
            <div>
                <label class="ls-label" for="password_confirmation">Confirm password</label>
                <input class="ls-input" id="password_confirmation" name="password_confirmation" type="password" required />
            </div>
        </div>
        <p class="ls-help">Minimum 10 characters, with mixed case and a number.</p>

        @if ($errors->any())
            <div class="rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-700">
                <ul class="list-disc space-y-1 pl-4">
                    @foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach
                </ul>
            </div>
        @endif

        <button type="submit" class="ls-btn ls-btn-primary w-full justify-center">Create admin & launch panel</button>
    </form>
</div>
@endsection
