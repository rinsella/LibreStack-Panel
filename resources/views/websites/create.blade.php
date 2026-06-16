@extends('layouts.app')
@section('title', 'New website')
@section('subheading', 'Provision directories and generate an Nginx server block.')

@section('content')
<form method="POST" action="{{ route('websites.store') }}" x-data="{ type: '{{ old('type', 'php') }}' }" class="max-w-3xl space-y-6">
    @csrf
    <x-card title="Website details">
        <div class="grid gap-5 sm:grid-cols-2">
            <div>
                <label class="ls-label" for="domain">Domain</label>
                <input class="ls-input" id="domain" name="domain" value="{{ old('domain') }}" placeholder="example.com" required />
                <p class="ls-help">Without http:// or www.</p>
            </div>
            <div>
                <label class="ls-label" for="system_username">System username</label>
                <input class="ls-input" id="system_username" name="system_username" value="{{ old('system_username') }}" placeholder="webuser" required />
                <p class="ls-help">Linux user that owns the files (a-z, 3-32 chars).</p>
            </div>
            <div>
                <label class="ls-label" for="type">Site type</label>
                <select class="ls-select" id="type" name="type" x-model="type">
                    @foreach ($siteTypes as $value => $label)
                        <option value="{{ $value }}" @selected(old('type', 'php') === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div x-show="['php','wordpress'].includes(type)">
                <label class="ls-label" for="php_version">PHP version</label>
                <select class="ls-select" id="php_version" name="php_version">
                    @foreach ($phpVersions as $v)
                        <option value="{{ $v }}" @selected(old('php_version', config('librestack.default_php')) === $v)>PHP {{ $v }}</option>
                    @endforeach
                </select>
            </div>
            <div x-show="['reverse_proxy','node_proxy'].includes(type)" class="sm:col-span-2">
                <label class="ls-label" for="upstream_url">Upstream URL</label>
                <input class="ls-input" id="upstream_url" name="upstream_url" value="{{ old('upstream_url') }}" placeholder="http://127.0.0.1:3000" />
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
            <div class="sm:col-span-2">
                <label class="ls-label" for="aliases">Custom aliases (optional)</label>
                <input class="ls-input" id="aliases" name="aliases" value="{{ old('aliases') }}" placeholder="alias1.com, alias2.com" />
                <p class="ls-help">Comma or space separated additional domains.</p>
            </div>
        </div>

        <div class="mt-5 flex flex-wrap gap-6 border-t border-slate-100 pt-4">
            <label class="flex items-center gap-2 text-sm text-slate-600">
                <input type="checkbox" name="www_alias" value="1" {{ old('www_alias', true) ? 'checked' : '' }} class="rounded border-slate-300 text-brand-600" />
                Add www alias
            </label>
            <label class="flex items-center gap-2 text-sm text-slate-600" x-show="['reverse_proxy','node_proxy'].includes(type)">
                <input type="checkbox" name="websocket" value="1" {{ old('websocket') ? 'checked' : '' }} class="rounded border-slate-300 text-brand-600" />
                Enable WebSocket support
            </label>
        </div>
    </x-card>

    <div class="flex justify-end gap-3">
        <a href="{{ route('websites.index') }}" class="ls-btn ls-btn-secondary">Cancel</a>
        <button class="ls-btn ls-btn-primary">Create website</button>
    </div>
</form>
@endsection
