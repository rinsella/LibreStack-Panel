@extends('layouts.app')
@section('title', 'New reverse proxy')

@section('content')
<form method="POST" action="{{ route('reverse-proxy.store') }}" class="max-w-2xl space-y-6">
    @csrf
    <input type="hidden" name="type" value="reverse_proxy" />
    <x-card title="Proxy configuration">
        <div class="grid gap-5 sm:grid-cols-2">
            <div>
                <label class="ls-label" for="domain">Domain</label>
                <input class="ls-input" id="domain" name="domain" value="{{ old('domain') }}" placeholder="app.example.com" required />
            </div>
            <div>
                <label class="ls-label" for="system_username">System username</label>
                <input class="ls-input" id="system_username" name="system_username" value="{{ old('system_username') }}" placeholder="appuser" required />
            </div>
            <div class="sm:col-span-2">
                <label class="ls-label" for="upstream_url">Upstream URL</label>
                <input class="ls-input" id="upstream_url" name="upstream_url" value="{{ old('upstream_url') }}" placeholder="http://127.0.0.1:3000" required />
            </div>
            @if ($owners->isNotEmpty())
            <div>
                <label class="ls-label" for="user_id">Owner (optional)</label>
                <select class="ls-select" id="user_id" name="user_id">
                    <option value="">— None —</option>
                    @foreach ($owners as $owner)
                        <option value="{{ $owner->id }}" @selected(old('user_id') == $owner->id)>{{ $owner->name }}</option>
                    @endforeach
                </select>
            </div>
            @endif
        </div>
        <div class="mt-5 flex flex-wrap gap-6 border-t border-slate-100 pt-4">
            <label class="flex items-center gap-2 text-sm text-slate-600">
                <input type="checkbox" name="www_alias" value="1" class="rounded border-slate-300 text-brand-600" /> www alias
            </label>
            <label class="flex items-center gap-2 text-sm text-slate-600">
                <input type="checkbox" name="websocket" value="1" {{ old('websocket') ? 'checked' : '' }} class="rounded border-slate-300 text-brand-600" /> WebSocket support
            </label>
            <label class="flex items-center gap-2 text-sm text-slate-600">
                <input type="checkbox" name="force_https" value="1" {{ old('force_https') ? 'checked' : '' }} class="rounded border-slate-300 text-brand-600" /> Force HTTPS (after SSL)
            </label>
        </div>
    </x-card>
    <div class="flex justify-end gap-3">
        <a href="{{ route('reverse-proxy.index') }}" class="ls-btn ls-btn-secondary">Cancel</a>
        <button class="ls-btn ls-btn-primary">Create reverse proxy</button>
    </div>
</form>
@endsection
