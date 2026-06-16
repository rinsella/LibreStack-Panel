@extends('layouts.app')
@section('title', 'Backups')
@section('subheading', 'Create, schedule, restore and download backups.')

@php use App\Support\Format; @endphp

@section('content')
<div class="grid gap-6 lg:grid-cols-3">
    <div class="lg:col-span-2">
        <x-card padding="p-0" title="Backups">
            <div class="overflow-x-auto">
                <table class="ls-table">
                    <thead><tr><th>Domain</th><th>Type</th><th>Size</th><th>Status</th><th>Created</th><th class="text-right">Actions</th></tr></thead>
                    <tbody>
                        @forelse ($backups as $backup)
                            <tr>
                                <td class="font-medium text-slate-800">{{ $backup->domain ?? ($backup->website->domain ?? '—') }}</td>
                                <td><span class="font-mono text-xs">{{ $backup->type }}</span></td>
                                <td class="text-slate-500">{{ $backup->size_bytes ? Format::bytes($backup->size_bytes) : '—' }}</td>
                                <td><x-badge :status="$backup->status" /></td>
                                <td class="text-slate-400">{{ $backup->created_at->diffForHumans() }}</td>
                                <td class="text-right">
                                    <div class="flex items-center justify-end gap-1">
                                        <a href="{{ route('backups.download', $backup) }}" class="rounded px-2 py-1 text-sm text-slate-600 hover:bg-slate-100">Download</a>
                                        <x-confirm :action="route('backups.restore', $backup)" method="POST" tone="primary" title="Restore this backup?" message="Existing files may be overwritten." confirm="Restore" trigger="Restore" triggerClass="rounded px-2 py-1 text-sm text-brand-700 hover:bg-brand-50" />
                                        <x-confirm :action="route('backups.destroy', $backup)" method="DELETE" title="Delete backup?" trigger="Delete" triggerClass="rounded px-2 py-1 text-sm text-red-600 hover:bg-red-50" />
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6"><x-empty icon="archive" title="No backups yet" message="Create a manual backup from the panel." /></td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-card>
        <div class="mt-4">{{ $backups->links() }}</div>
    </div>

    <div class="space-y-6">
        <x-card title="Create backup">
            <form method="POST" action="{{ route('backups.store') }}" class="space-y-4">
                @csrf
                <div>
                    <label class="ls-label" for="b_website">Website</label>
                    <select class="ls-select" id="b_website" name="website_id" required>
                        @foreach ($websites as $w)<option value="{{ $w->id }}">{{ $w->domain }}</option>@endforeach
                    </select>
                </div>
                <div>
                    <label class="ls-label" for="b_type">Type</label>
                    <select class="ls-select" id="b_type" name="type">
                        <option value="full">Files + database</option>
                        <option value="files">Files only</option>
                        <option value="database">Database only</option>
                    </select>
                </div>
                <button class="ls-btn ls-btn-primary w-full justify-center">Create backup</button>
            </form>
        </x-card>

        <x-card title="Schedules">
            <form method="POST" action="{{ route('backup-schedules.store') }}" class="space-y-3">
                @csrf
                <select class="ls-select" name="website_id" required>
                    @foreach ($websites as $w)<option value="{{ $w->id }}">{{ $w->domain }}</option>@endforeach
                </select>
                <div class="grid grid-cols-3 gap-2">
                    <select class="ls-select" name="type"><option value="full">Full</option><option value="files">Files</option><option value="database">DB</option></select>
                    <select class="ls-select" name="frequency"><option value="daily">Daily</option><option value="weekly">Weekly</option><option value="monthly">Monthly</option></select>
                    <input class="ls-input" name="retention" type="number" min="1" max="90" value="7" title="Retention (days)" />
                </div>
                <button class="ls-btn ls-btn-secondary w-full justify-center">Add schedule</button>
            </form>

            <ul class="mt-4 space-y-2">
                @forelse ($schedules as $s)
                    <li class="flex items-center justify-between rounded-lg bg-slate-50 px-3 py-2 text-sm">
                        <span>{{ $s->website->domain ?? '—' }} · {{ $s->frequency }} · {{ $s->type }}</span>
                        <form method="POST" action="{{ route('backup-schedules.destroy', $s) }}" onsubmit="return confirm('Remove schedule?')">@csrf @method('DELETE')<button class="text-red-500">&times;</button></form>
                    </li>
                @empty
                    <li class="text-sm text-slate-400">No schedules configured.</li>
                @endforelse
            </ul>
            <p class="ls-help mt-3">Schedules run via the Laravel scheduler (see docs).</p>
        </x-card>
    </div>
</div>
@endsection
