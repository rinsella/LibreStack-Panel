@extends('layouts.app')
@section('title', 'Job #' . $job->id)
@section('subheading', $job->type)

@section('content')
<div class="mb-4"><a href="{{ route('jobs.index') }}" class="text-sm text-brand-700">&larr; Back to jobs</a></div>

<div class="grid gap-6 lg:grid-cols-3">
    <x-card title="Details">
        <dl class="space-y-3 text-sm">
            <div class="flex justify-between"><dt class="text-slate-500">Status</dt><dd><x-badge :status="$job->status" /></dd></div>
            <div class="flex justify-between"><dt class="text-slate-500">Progress</dt><dd>{{ $job->progress }}%</dd></div>
            <div class="flex justify-between"><dt class="text-slate-500">Created by</dt><dd>{{ $job->creator->name ?? 'system' }}</dd></div>
            <div class="flex justify-between"><dt class="text-slate-500">Started</dt><dd>{{ $job->started_at?->diffForHumans() ?? '—' }}</dd></div>
            <div class="flex justify-between"><dt class="text-slate-500">Finished</dt><dd>{{ $job->finished_at?->diffForHumans() ?? '—' }}</dd></div>
        </dl>
        @if ($job->message)<p class="mt-4 rounded-lg bg-slate-50 p-3 text-sm text-slate-600">{{ $job->message }}</p>@endif
    </x-card>

    <x-card title="Log output" class="lg:col-span-2" padding="p-0">
        <div class="max-h-[60vh] divide-y divide-slate-50 overflow-auto">
            @forelse ($job->logs as $log)
                <div class="flex gap-3 px-4 py-2 text-sm">
                    <span class="w-16 shrink-0 text-xs font-medium uppercase {{ $log->level === 'error' ? 'text-red-500' : ($log->level === 'success' ? 'text-emerald-600' : 'text-slate-400') }}">{{ $log->level }}</span>
                    <span class="text-slate-700">{{ $log->line }}</span>
                    <span class="ml-auto shrink-0 text-xs text-slate-300">{{ $log->created_at->format('H:i:s') }}</span>
                </div>
            @empty
                <div class="px-4 py-8 text-center text-sm text-slate-400">No log entries.</div>
            @endforelse
        </div>
    </x-card>
</div>
@endsection
