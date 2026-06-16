@extends('layouts.app')
@section('title', 'Edit ' . $website->domain)

@section('content')
<form method="POST" action="{{ route('websites.update', $website) }}" x-data="{ type: '{{ old('type', $website->type) }}' }" class="max-w-3xl space-y-6">
    @csrf
    @method('PUT')
    <x-card title="Website details" subtitle="{{ $website->domain }}">
        <div class="grid gap-5 sm:grid-cols-2">
            <div>
                <label class="ls-label">Domain</label>
                <input class="ls-input bg-slate-50" value="{{ $website->domain }}" disabled />
                <p class="ls-help">Domain cannot be changed after creation.</p>
            </div>
            <div>
                <label class="ls-label" for="type">Site type</label>
                <select class="ls-select" id="type" name="type" x-model="type">
                    @foreach ($siteTypes as $value => $label)
                        <option value="{{ $value }}" @selected(old('type', $website->type) === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div x-show="['php','wordpress'].includes(type)">
                <label class="ls-label" for="php_version">PHP version</label>
                <select class="ls-select" id="php_version" name="php_version">
                    @foreach ($phpVersions as $v)
                        <option value="{{ $v }}" @selected(old('php_version', $website->php_version) === $v)>PHP {{ $v }}</option>
                    @endforeach
                </select>
            </div>
            <div x-show="['reverse_proxy','node_proxy'].includes(type)" class="sm:col-span-2">
                <label class="ls-label" for="upstream_url">Upstream URL</label>
                <input class="ls-input" id="upstream_url" name="upstream_url" value="{{ old('upstream_url', $website->upstream_url) }}" placeholder="http://127.0.0.1:3000" />
            </div>
            @if ($owners->isNotEmpty())
            <div>
                <label class="ls-label" for="user_id">Owner</label>
                <select class="ls-select" id="user_id" name="user_id">
                    <option value="">— None —</option>
                    @foreach ($owners as $owner)
                        <option value="{{ $owner->id }}" @selected(old('user_id', $website->user_id) == $owner->id)>{{ $owner->name }}</option>
                    @endforeach
                </select>
            </div>
            @endif
            <div class="sm:col-span-2">
                <label class="ls-label" for="aliases">Custom aliases</label>
                <input class="ls-input" id="aliases" name="aliases" value="{{ old('aliases', $website->aliases->pluck('domain')->implode(', ')) }}" />
            </div>
        </div>

        <div class="mt-5 flex flex-wrap gap-6 border-t border-slate-100 pt-4">
            <label class="flex items-center gap-2 text-sm text-slate-600">
                <input type="checkbox" name="www_alias" value="1" {{ old('www_alias', $website->www_alias) ? 'checked' : '' }} class="rounded border-slate-300 text-brand-600" />
                www alias
            </label>
            <label class="flex items-center gap-2 text-sm text-slate-600" x-show="['reverse_proxy','node_proxy'].includes(type)">
                <input type="checkbox" name="websocket" value="1" {{ old('websocket', $website->websocket) ? 'checked' : '' }} class="rounded border-slate-300 text-brand-600" />
                WebSocket support
            </label>
        </div>
    </x-card>

    <div class="flex justify-end gap-3">
        <a href="{{ route('websites.show', $website) }}" class="ls-btn ls-btn-secondary">Cancel</a>
        <button class="ls-btn ls-btn-primary">Save changes</button>
    </div>
</form>
@endsection
