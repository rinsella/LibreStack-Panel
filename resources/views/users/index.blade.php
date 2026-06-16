@extends('layouts.app')
@section('title', 'Users')
@section('subheading', 'Manage panel users and their roles.')

@section('content')
<x-card padding="p-0">
    <div class="flex flex-col gap-3 border-b border-slate-100 p-4 sm:flex-row sm:items-center sm:justify-between">
        <form method="GET" class="w-full sm:max-w-xs"><input class="ls-input" name="q" value="{{ $search }}" placeholder="Search users…" /></form>
        <a href="{{ route('users.create') }}" class="ls-btn ls-btn-primary justify-center">@include('partials.icons', ['name' => 'plus', 'class' => 'h-4 w-4']) New user</a>
    </div>
    <div class="overflow-x-auto">
        <table class="ls-table">
            <thead><tr><th>Name</th><th>Email</th><th>Roles</th><th>Status</th><th>Last login</th><th class="text-right">Actions</th></tr></thead>
            <tbody>
                @forelse ($users as $user)
                    <tr>
                        <td class="font-medium text-slate-800">{{ $user->name }}</td>
                        <td class="text-slate-500">{{ $user->email }}</td>
                        <td><div class="flex flex-wrap gap-1">@forelse ($user->roles as $role)<span class="rounded bg-slate-100 px-2 py-0.5 text-xs">{{ $role->label }}</span>@empty<span class="text-xs text-slate-400">none</span>@endforelse</div></td>
                        <td><x-badge :status="$user->status" /></td>
                        <td class="text-slate-400">{{ $user->last_login_at?->diffForHumans() ?? 'never' }}</td>
                        <td class="text-right">
                            <div class="flex items-center justify-end gap-1">
                                <a href="{{ route('users.edit', $user) }}" class="rounded px-2 py-1 text-sm text-slate-600 hover:bg-slate-100">Edit</a>
                                <x-confirm :action="route('users.destroy', $user)" method="DELETE" title="Delete {{ $user->name }}?" trigger="Delete" triggerClass="rounded px-2 py-1 text-sm text-red-600 hover:bg-red-50" />
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6"><x-empty icon="users" title="No users" /></td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-card>
<div class="mt-4">{{ $users->links() }}</div>
@endsection
