@extends('layouts.app')
@section('title', 'Jobs')
@section('subheading', 'Background and long-running operations with full logs.')

@section('content')
<x-card padding="p-0">
    <div class="overflow-x-auto">
        <table class="ls-table">
            <thead><tr><th>#</th><th>Type</th><th>Status</th><th>Message</th><th>By</th><th>When</th></tr></thead>
            <tbody>
                @forelse ($jobs as $job)
                    <tr class="cursor-pointer" onclick="window.location='{{ route('jobs.show', $job) }}'">
                        <td class="text-slate-400">{{ $job->id }}</td>
                        <td class="font-mono text-xs">{{ $job->type }}</td>
                        <td><x-badge :status="$job->status" /></td>
                        <td class="max-w-sm truncate text-slate-500">{{ $job->message }}</td>
                        <td>{{ $job->creator->name ?? 'system' }}</td>
                        <td class="text-slate-400">{{ $job->created_at->diffForHumans() }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6"><x-empty icon="lightning" title="No jobs yet" message="Jobs appear here when you run operations like backups or SSL issuance." /></td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-card>
<div class="mt-4">{{ $jobs->links() }}</div>
@endsection
