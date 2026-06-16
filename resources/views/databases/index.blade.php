@extends('layouts.app')
@section('title', 'Databases')
@section('subheading', 'Manage MariaDB/MySQL databases and users.')

@php use App\Support\Format; @endphp

@section('content')
@if (session('db_credentials'))
    @php $c = session('db_credentials'); @endphp
    <div class="mb-6 rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-800">
        <p class="font-semibold">Database created — this password is shown only once:</p>
        <ul class="mt-2 space-y-1 font-mono text-xs">
            <li>Database: {{ $c['name'] }}</li>
            <li>User: {{ $c['username'] }}</li>
            <li>Password: {{ $c['password'] }}</li>
        </ul>
    </div>
@endif

<x-card padding="p-0">
    <div class="flex flex-col gap-3 border-b border-slate-100 p-4 sm:flex-row sm:items-center sm:justify-between">
        <form method="GET" class="w-full sm:max-w-xs">
            <input class="ls-input" name="q" value="{{ $search }}" placeholder="Search databases…" />
        </form>
        <a href="{{ route('databases.create') }}" class="ls-btn ls-btn-primary justify-center">@include('partials.icons', ['name' => 'plus', 'class' => 'h-4 w-4']) New database</a>
    </div>
    <div class="overflow-x-auto">
        <table class="ls-table">
            <thead><tr><th>Database</th><th>Website</th><th>Users</th><th>Size</th><th class="text-right">Actions</th></tr></thead>
            <tbody>
                @forelse ($items as $db)
                    <tr>
                        <td class="font-mono font-medium text-slate-800">{{ $db->name }}</td>
                        <td>{{ $db->website->domain ?? '—' }}</td>
                        <td>
                            <div class="flex flex-wrap gap-1">
                                @forelse ($db->users as $u)
                                    <span class="inline-flex items-center gap-1 rounded bg-slate-100 px-2 py-0.5 text-xs">
                                        {{ $u->username }}
                                        <form method="POST" action="{{ route('database-users.destroy', $u) }}" onsubmit="return confirm('Drop user {{ $u->username }}?')">@csrf @method('DELETE')<button class="text-red-500">&times;</button></form>
                                    </span>
                                @empty <span class="text-xs text-slate-400">none</span> @endforelse
                            </div>
                        </td>
                        <td class="text-slate-500">{{ $db->size_bytes ? Format::bytes($db->size_bytes) : '—' }}</td>
                        <td class="text-right">
                            <div class="flex items-center justify-end gap-1">
                                <form method="POST" action="{{ route('databases.export', $db) }}" class="inline">@csrf<button class="rounded-lg px-2.5 py-1.5 text-sm text-slate-600 hover:bg-slate-100">Export</button></form>
                                <x-confirm :action="route('databases.destroy', $db)" method="DELETE" title="Drop database {{ $db->name }}?" message="All data will be permanently lost." confirm="Drop database" trigger="Delete" />
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5"><x-empty icon="database" title="No databases" message="Create your first database."><x-slot:action><a href="{{ route('databases.create') }}" class="ls-btn ls-btn-primary">New database</a></x-slot:action></x-empty></td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-card>
<div class="mt-4">{{ $items->links() }}</div>
@endsection
