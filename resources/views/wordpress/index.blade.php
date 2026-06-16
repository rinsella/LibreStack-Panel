@extends('layouts.app')
@section('title', 'WordPress')
@section('subheading', 'One-click WordPress installs onto your PHP websites.')

@section('content')
<x-card padding="p-0">
    <div class="overflow-x-auto">
        <table class="ls-table">
            <thead><tr><th>Domain</th><th>Type</th><th>WP version</th><th class="text-right">Install</th></tr></thead>
            <tbody>
                @forelse ($sites as $site)
                    <tr x-data="{ confirm: false }">
                        <td class="font-medium text-slate-800">{{ $site->domain }}</td>
                        <td><span class="font-mono text-xs">{{ $site->type }}</span></td>
                        <td>{{ $site->wp_version ? 'v' . $site->wp_version : '—' }}</td>
                        <td class="text-right">
                            <form method="POST" action="{{ route('wordpress.install') }}" class="inline-flex items-center gap-2">
                                @csrf
                                <input type="hidden" name="website_id" value="{{ $site->id }}" />
                                <label class="flex items-center gap-1 text-xs text-slate-500">
                                    <input type="checkbox" name="confirm" value="1" class="rounded border-slate-300" /> overwrite
                                </label>
                                <button class="ls-btn ls-btn-primary">{{ $site->wp_version ? 'Reinstall' : 'Install WordPress' }}</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="4"><x-empty icon="wordpress" title="No eligible sites" message="Create a PHP or WordPress website first." /></td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-card>
@endsection
