@extends('layouts.app')
@section('title', 'Audit logs')
@section('subheading', 'A tamper-evident trail of important actions.')

@section('content')
<x-card padding="p-0">
    <form method="GET" class="flex flex-col gap-3 border-b border-slate-100 p-4 sm:flex-row sm:items-center">
        <select class="ls-select sm:!w-56" name="action" onchange="this.form.submit()">
            <option value="">All actions</option>
            @foreach ($actions as $a)<option value="{{ $a }}" @selected($action === $a)>{{ $a }}</option>@endforeach
        </select>
        <input class="ls-input sm:!w-56" name="q" value="{{ $search }}" placeholder="Search actions…" />
        <button class="ls-btn ls-btn-secondary">Filter</button>
    </form>
    <div class="overflow-x-auto">
        <table class="ls-table">
            <thead><tr><th>Action</th><th>User</th><th>Target</th><th>IP</th><th>When</th></tr></thead>
            <tbody>
                @forelse ($logs as $log)
                    <tr>
                        <td class="font-mono text-xs">{{ $log->action }}</td>
                        <td>{{ $log->user->name ?? 'system' }}</td>
                        <td class="text-slate-500">{{ $log->target_type ? $log->target_type . ($log->target_id ? ' #' . $log->target_id : '') : '—' }}</td>
                        <td class="text-slate-400">{{ $log->ip_address }}</td>
                        <td class="text-slate-400">{{ $log->created_at?->format('Y-m-d H:i') }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5"><x-empty icon="eye" title="No audit entries" /></td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-card>
<div class="mt-4">{{ $logs->links() }}</div>
@endsection
