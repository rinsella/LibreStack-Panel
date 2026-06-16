@extends('layouts.app')
@section('title', 'Reverse proxy')
@section('subheading', 'Proxy domains to local applications (Node.js, etc.).')

@section('content')
<div class="mb-4 flex justify-end">
    <a href="{{ route('reverse-proxy.create') }}" class="ls-btn ls-btn-primary">@include('partials.icons', ['name' => 'plus', 'class' => 'h-4 w-4']) New reverse proxy</a>
</div>

<div class="mb-6 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800">
    <strong>Process manager:</strong> starting/stopping the upstream app via systemd is on the roadmap
    (<span class="font-medium">Coming soon</span>). This module configures the Nginx reverse proxy only.
</div>

<x-card padding="p-0">
    <div class="overflow-x-auto">
        <table class="ls-table">
            <thead><tr><th>Domain</th><th>Upstream</th><th>WebSocket</th><th>Status</th><th class="text-right">Actions</th></tr></thead>
            <tbody>
                @forelse ($sites as $site)
                    <tr>
                        <td class="font-medium text-slate-800">{{ $site->domain }}</td>
                        <td class="font-mono text-xs">{{ $site->upstream_url }}</td>
                        <td>{!! $site->websocket ? '<span class="text-emerald-600">yes</span>' : '<span class="text-slate-400">no</span>' !!}</td>
                        <td><x-badge :status="$site->enabled ? 'active' : 'inactive'" /></td>
                        <td class="text-right">
                            <x-confirm :action="route('reverse-proxy.destroy', $site)" method="DELETE"
                                title="Delete proxy {{ $site->domain }}?" trigger="Delete" />
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5"><x-empty icon="switch" title="No reverse proxies" message="Create one to route a domain to a local app." /></td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-card>
<div class="mt-4">{{ $sites->links() }}</div>
@endsection
