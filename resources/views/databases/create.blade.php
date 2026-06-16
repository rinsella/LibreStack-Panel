@extends('layouts.app')
@section('title', 'New database')

@section('content')
<form method="POST" action="{{ route('databases.store') }}" class="max-w-2xl space-y-6">
    @csrf
    <x-card title="Database & user">
        <div class="grid gap-5 sm:grid-cols-2">
            <div>
                <label class="ls-label" for="name">Database name</label>
                <input class="ls-input" id="name" name="name" value="{{ old('name') }}" placeholder="myapp" required />
            </div>
            <div>
                <label class="ls-label" for="website_id">Link to website (optional)</label>
                <select class="ls-select" id="website_id" name="website_id">
                    <option value="">— None —</option>
                    @foreach ($websites as $w)
                        <option value="{{ $w->id }}" @selected(old('website_id') == $w->id)>{{ $w->domain }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="ls-label" for="username">Database user</label>
                <input class="ls-input" id="username" name="username" value="{{ old('username') }}" placeholder="myapp_user" required />
            </div>
            <div x-data="{ pw: '{{ $suggestedPassword }}' }">
                <label class="ls-label" for="password">Password</label>
                <div class="flex gap-2">
                    <input class="ls-input font-mono" id="password" name="password" x-model="pw" required />
                    <button type="button" @click="navigator.clipboard.writeText(pw)" class="ls-btn ls-btn-secondary" title="Copy">Copy</button>
                </div>
                <p class="ls-help">A strong password was generated. Save it — it is shown only once.</p>
            </div>
        </div>
    </x-card>
    <div class="flex justify-end gap-3">
        <a href="{{ route('databases.index') }}" class="ls-btn ls-btn-secondary">Cancel</a>
        <button class="ls-btn ls-btn-primary">Create database</button>
    </div>
</form>
@endsection
