@extends('layouts.app')
@section('title', 'Cron jobs')
@section('subheading', 'Schedule recurring commands. Managed entries are synced to the system crontab.')

@php use App\Services\System\CronService; @endphp

@section('content')
<div class="mb-4 flex justify-end">
    <a href="{{ route('cron.create') }}" class="ls-btn ls-btn-primary">@include('partials.icons', ['name' => 'plus', 'class' => 'h-4 w-4']) New cron job</a>
</div>

<x-card padding="p-0">
    <div class="overflow-x-auto">
        <table class="ls-table">
            <thead><tr><th>Name</th><th>Schedule</th><th>Command</th><th>Status</th><th class="text-right">Actions</th></tr></thead>
            <tbody>
                @forelse ($jobs as $job)
                    <tr>
                        <td class="font-medium text-slate-800">{{ $job->name }}</td>
                        <td class="font-mono text-xs">{{ $job->schedule }}</td>
                        <td class="max-w-xs truncate font-mono text-xs text-slate-500">
                            {{ $job->command }}
                            @if (CronService::isDangerous($job->command))
                                <span class="ml-1 text-amber-600" title="Potentially dangerous command">⚠</span>
                            @endif
                        </td>
                        <td><x-badge :status="$job->enabled ? 'active' : 'inactive'">{{ $job->enabled ? 'Enabled' : 'Disabled' }}</x-badge></td>
                        <td class="text-right">
                            <div class="flex items-center justify-end gap-1">
                                <form method="POST" action="{{ route('cron.toggle', $job) }}" class="inline">@csrf<button class="rounded px-2 py-1 text-sm text-slate-600 hover:bg-slate-100">{{ $job->enabled ? 'Disable' : 'Enable' }}</button></form>
                                <a href="{{ route('cron.edit', $job) }}" class="rounded px-2 py-1 text-sm text-slate-600 hover:bg-slate-100">Edit</a>
                                <x-confirm :action="route('cron.destroy', $job)" method="DELETE" title="Delete cron job?" trigger="Delete" triggerClass="rounded px-2 py-1 text-sm text-red-600 hover:bg-red-50" />
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5"><x-empty icon="clock" title="No cron jobs" message="Add a scheduled command to get started." /></td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-card>
@endsection
