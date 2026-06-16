@extends('layouts.app')
@section('title', 'Dashboard')
@section('subheading', $info['hostname'] . ' &middot; ' . $info['os'])

@php use App\Support\Format; @endphp

@section('content')
{{-- Stat cards --}}
<div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
    @php
        $stats = [
            ['label' => 'CPU usage', 'value' => $info['cpu'] . '%', 'icon' => 'lightning', 'pct' => $info['cpu']],
            ['label' => 'Memory', 'value' => $info['memory']['percent'] . '%', 'icon' => 'database', 'pct' => $info['memory']['percent'], 'sub' => Format::bytes($info['memory']['used']) . ' / ' . Format::bytes($info['memory']['total'])],
            ['label' => 'Disk', 'value' => $info['disk']['percent'] . '%', 'icon' => 'archive', 'pct' => $info['disk']['percent'], 'sub' => Format::bytes($info['disk']['used']) . ' / ' . Format::bytes($info['disk']['total'])],
            ['label' => 'Load (1m)', 'value' => $info['load']['1m'], 'icon' => 'cog', 'pct' => min(100, $info['load']['1m'] * 25), 'sub' => 'Uptime ' . $info['uptime']],
        ];
    @endphp
    @foreach ($stats as $stat)
        <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <div class="flex items-center justify-between">
                <p class="text-sm font-medium text-slate-500">{{ $stat['label'] }}</p>
                <span class="text-slate-300">@include('partials.icons', ['name' => $stat['icon'], 'class' => 'h-5 w-5'])</span>
            </div>
            <p class="mt-2 text-2xl font-semibold text-slate-900">{{ $stat['value'] }}</p>
            <div class="mt-3 h-1.5 w-full overflow-hidden rounded-full bg-slate-100">
                <div class="h-full rounded-full {{ ($stat['pct'] ?? 0) > 85 ? 'bg-red-500' : 'bg-brand-500' }}" style="width: {{ min(100, $stat['pct'] ?? 0) }}%"></div>
            </div>
            @if (!empty($stat['sub']))<p class="mt-2 text-xs text-slate-400">{{ $stat['sub'] }}</p>@endif
        </div>
    @endforeach
</div>

<div class="mt-6 grid gap-6 lg:grid-cols-3">
    {{-- Server info --}}
    <x-card title="Server" class="lg:col-span-1">
        <dl class="space-y-3 text-sm">
            @foreach ([
                'Hostname' => $info['hostname'],
                'OS' => $info['os'],
                'Kernel' => $info['kernel'],
                'Server IP' => $info['server_ip'] ?? 'n/a',
                'Load avg' => $info['load']['1m'] . ' / ' . $info['load']['5m'] . ' / ' . $info['load']['15m'],
            ] as $label => $value)
                <div class="flex items-center justify-between gap-4">
                    <dt class="text-slate-500">{{ $label }}</dt>
                    <dd class="truncate text-right font-medium text-slate-800">{{ $value }}</dd>
                </div>
            @endforeach
        </dl>
    </x-card>

    {{-- Services + counts --}}
    <x-card title="Services" class="lg:col-span-1">
        <ul class="space-y-3">
            @foreach ($services as $name => $status)
                <li class="flex items-center justify-between">
                    <span class="font-mono text-sm text-slate-700">{{ $name }}</span>
                    <x-badge :status="$status === 'active' ? 'active' : ($status === 'unknown' ? 'unknown' : 'failed')">{{ $status }}</x-badge>
                </li>
            @endforeach
        </ul>
        <div class="mt-5 grid grid-cols-3 gap-3 border-t border-slate-100 pt-4 text-center">
            <div><p class="text-xl font-semibold text-slate-900">{{ $counts['websites'] }}</p><p class="text-xs text-slate-400">Websites</p></div>
            <div><p class="text-xl font-semibold text-slate-900">{{ $counts['databases'] }}</p><p class="text-xs text-slate-400">Databases</p></div>
            <div><p class="text-xl font-semibold text-slate-900">{{ $counts['backups'] }}</p><p class="text-xs text-slate-400">Backups</p></div>
        </div>
    </x-card>

    {{-- Recent jobs --}}
    <x-card title="Recent jobs" class="lg:col-span-1" padding="p-0">
        @forelse ($recentJobs as $job)
            <a href="{{ route('jobs.show', $job) }}" class="flex items-center justify-between border-b border-slate-50 px-5 py-3 hover:bg-slate-50">
                <div>
                    <p class="text-sm font-medium text-slate-800">{{ $job->type }}</p>
                    <p class="text-xs text-slate-400">{{ $job->created_at->diffForHumans() }}</p>
                </div>
                <x-badge :status="$job->status" />
            </a>
        @empty
            <div class="px-5 py-8 text-center text-sm text-slate-400">No jobs yet.</div>
        @endforelse
    </x-card>
</div>

{{-- Recent audit --}}
<div class="mt-6">
    <x-card title="Recent activity" padding="p-0">
        <div class="overflow-x-auto">
            <table class="ls-table">
                <thead>
                    <tr><th>Action</th><th>User</th><th>IP</th><th>When</th></tr>
                </thead>
                <tbody>
                    @forelse ($recentAudit as $log)
                        <tr>
                            <td class="font-mono text-xs">{{ $log->action }}</td>
                            <td>{{ $log->user->name ?? 'system' }}</td>
                            <td class="text-slate-400">{{ $log->ip_address }}</td>
                            <td class="text-slate-400">{{ $log->created_at?->diffForHumans() }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="py-8 text-center text-slate-400">No activity recorded yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-card>
</div>
@endsection
