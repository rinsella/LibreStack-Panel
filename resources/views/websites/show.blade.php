@extends('layouts.app')
@section('title', $website->domain)
@section('subheading', config('librestack.site_types')[$website->type] ?? $website->type)

@section('content')
<div class="grid gap-6 lg:grid-cols-3">
    <div class="space-y-6 lg:col-span-2">
        <x-card title="Overview">
            <dl class="grid gap-x-6 gap-y-4 sm:grid-cols-2">
                @foreach ([
                    'Domain' => $website->domain,
                    'Type' => $website->type,
                    'PHP version' => $website->php_version ?? '—',
                    'Owner' => $website->owner->name ?? '—',
                    'System user' => $website->system_username,
                    'Upstream' => $website->upstream_url ?? '—',
                ] as $label => $value)
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-slate-400">{{ $label }}</dt>
                        <dd class="mt-0.5 text-sm font-medium text-slate-800">{{ $value }}</dd>
                    </div>
                @endforeach
                <div class="sm:col-span-2">
                    <dt class="text-xs uppercase tracking-wide text-slate-400">Document root</dt>
                    <dd class="mt-0.5 break-all font-mono text-sm text-slate-700">{{ $website->document_root }}</dd>
                </div>
            </dl>
        </x-card>

        <x-card title="Generated Nginx config" subtitle="# Managed by LibreStack Panel">
            <pre class="max-h-96 overflow-auto rounded-xl bg-navy-950 p-4 text-xs leading-relaxed text-slate-200">{{ $configPreview }}</pre>
        </x-card>
    </div>

    <div class="space-y-6">
        <x-card title="Status">
            <div class="space-y-4">
                <div class="flex items-center justify-between">
                    <span class="text-sm text-slate-500">State</span>
                    <x-badge :status="$website->isSuspended() ? 'suspended' : ($website->enabled ? 'active' : 'inactive')">
                        {{ $website->isSuspended() ? 'Suspended' : ($website->enabled ? 'Active' : 'Disabled') }}
                    </x-badge>
                </div>
                <div class="flex items-center justify-between">
                    <span class="text-sm text-slate-500">SSL</span>
                    <x-badge :status="$website->ssl_enabled ? 'active' : 'unknown'">{{ $website->ssl_enabled ? 'Enabled' : 'None' }}</x-badge>
                </div>
            </div>
        </x-card>

        <x-card title="Actions">
            <div class="space-y-2">
                <a href="{{ route('websites.edit', $website) }}" class="ls-btn ls-btn-secondary w-full justify-center">Edit website</a>
                @if (in_array($website->type, ['php', 'wordpress'], true))
                    <a href="{{ route('php-settings.edit', $website) }}" class="ls-btn ls-btn-secondary w-full justify-center">@include('partials.icons', ['name' => 'adjustments', 'class' => 'h-4 w-4']) PHP settings</a>
                @endif
                <form method="POST" action="{{ route('websites.redeploy', $website) }}">
                    @csrf
                    <button class="ls-btn ls-btn-secondary w-full justify-center">@include('partials.icons', ['name' => 'refresh', 'class' => 'h-4 w-4']) Redeploy Nginx</button>
                </form>
                <form method="POST" action="{{ route('websites.toggle', $website) }}">
                    @csrf
                    <button class="ls-btn ls-btn-secondary w-full justify-center">{{ $website->enabled ? 'Disable site' : 'Enable site' }}</button>
                </form>
                <form method="POST" action="{{ route('websites.suspend', $website) }}">
                    @csrf
                    <button class="ls-btn ls-btn-secondary w-full justify-center">{{ $website->isSuspended() ? 'Unsuspend' : 'Suspend' }}</button>
                </form>
                <x-confirm :action="route('websites.destroy', $website)" method="DELETE"
                    title="Delete {{ $website->domain }}?"
                    message="The Nginx config will be removed."
                    confirm="Delete website"
                    triggerClass="ls-btn ls-btn-danger w-full justify-center"
                    trigger="Delete website">
                    <x-slot:fields>
                        <label class="flex items-center gap-2 text-sm text-slate-600">
                            <input type="checkbox" name="delete_files" value="1" class="rounded border-slate-300 text-red-600" />
                            Also delete website files
                        </label>
                    </x-slot:fields>
                </x-confirm>
            </div>
        </x-card>

        @if ($website->aliases->isNotEmpty())
            <x-card title="Aliases">
                <div class="flex flex-wrap gap-2">
                    @foreach ($website->aliases as $alias)
                        <span class="rounded-lg bg-slate-100 px-2.5 py-1 text-xs text-slate-600">{{ $alias->domain }}</span>
                    @endforeach
                </div>
            </x-card>
        @endif
    </div>
</div>
@endsection
