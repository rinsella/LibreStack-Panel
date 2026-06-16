@extends('layouts.app')
@section('title', 'Websites')
@section('subheading', 'Create and manage the sites served by this server.')

@section('content')
<x-card padding="p-0">
    <div class="flex flex-col gap-3 border-b border-slate-100 p-4 sm:flex-row sm:items-center sm:justify-between">
        <form method="GET" class="relative w-full sm:max-w-xs">
            <input class="ls-input pl-9" name="q" value="{{ $search }}" placeholder="Search domains…" />
            <span class="absolute left-3 top-2.5 text-slate-400">@include('partials.icons', ['name' => 'globe', 'class' => 'h-4 w-4'])</span>
        </form>
        <a href="{{ route('websites.create') }}" class="ls-btn ls-btn-primary justify-center">
            @include('partials.icons', ['name' => 'plus', 'class' => 'h-4 w-4']) New website
        </a>
    </div>

    <div class="overflow-x-auto">
        <table class="ls-table">
            <thead>
                <tr><th>Domain</th><th>Type</th><th>Owner</th><th>SSL</th><th>Status</th><th class="text-right">Actions</th></tr>
            </thead>
            <tbody>
                @forelse ($websites as $website)
                    <tr>
                        <td>
                            <a href="{{ route('websites.show', $website) }}" class="font-medium text-brand-700 hover:underline">{{ $website->domain }}</a>
                            <p class="text-xs text-slate-400">{{ $website->document_root }}</p>
                        </td>
                        <td><span class="font-mono text-xs">{{ $website->type }}</span></td>
                        <td>{{ $website->owner->name ?? '—' }}</td>
                        <td>
                            @if ($website->ssl_enabled)<x-badge status="active">HTTPS</x-badge>
                            @else<span class="text-xs text-slate-400">none</span>@endif
                        </td>
                        <td>
                            <x-badge :status="$website->isSuspended() ? 'suspended' : ($website->enabled ? 'active' : 'inactive')">
                                {{ $website->isSuspended() ? 'Suspended' : ($website->enabled ? 'Active' : 'Disabled') }}
                            </x-badge>
                        </td>
                        <td>
                            <div class="flex items-center justify-end gap-1">
                                <a href="{{ route('websites.edit', $website) }}" class="rounded-lg px-2.5 py-1.5 text-sm text-slate-600 hover:bg-slate-100">Edit</a>
                                <x-confirm :action="route('websites.destroy', $website)" method="DELETE"
                                    title="Delete {{ $website->domain }}?"
                                    message="The Nginx config will be removed. Optionally delete website files too."
                                    confirm="Delete website" trigger="Delete">
                                    <x-slot:fields>
                                        <label class="flex items-center gap-2 text-sm text-slate-600">
                                            <input type="checkbox" name="delete_files" value="1" class="rounded border-slate-300 text-red-600" />
                                            Also delete website files on disk
                                        </label>
                                    </x-slot:fields>
                                </x-confirm>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6">
                        <x-empty icon="globe" title="No websites yet" message="Create your first website to get started.">
                            <x-slot:action>
                                <a href="{{ route('websites.create') }}" class="ls-btn ls-btn-primary">Create website</a>
                            </x-slot:action>
                        </x-empty>
                    </td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-card>

<div class="mt-4">{{ $websites->links() }}</div>
@endsection
