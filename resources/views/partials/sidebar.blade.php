@php
    $nav = [
        ['route' => 'dashboard',   'label' => 'Dashboard',     'perm' => null,             'icon' => 'home'],
        ['route' => 'websites.index','label' => 'Websites',     'perm' => 'manage_websites','icon' => 'globe'],
        ['route' => 'ssl.index',    'label' => 'SSL',           'perm' => 'manage_ssl',     'icon' => 'lock'],
        ['route' => 'databases.index','label' => 'Databases',   'perm' => 'manage_databases','icon' => 'database'],
        ['route' => 'file-manager.index','label' => 'File Manager','perm' => 'manage_websites','icon' => 'folder'],
        ['route' => 'backups.index','label' => 'Backups',       'perm' => 'manage_backups', 'icon' => 'archive'],
        ['route' => 'wordpress.index','label' => 'WordPress',   'perm' => 'manage_websites','icon' => 'wordpress'],
        ['route' => 'reverse-proxy.index','label' => 'Reverse Proxy','perm' => 'manage_websites','icon' => 'switch'],
        ['route' => 'services.index','label' => 'Services',     'perm' => 'manage_services','icon' => 'cog'],
        ['route' => 'firewall.index','label' => 'Firewall',     'perm' => 'manage_firewall','icon' => 'shield'],
        ['route' => 'cron.index',   'label' => 'Cron Jobs',     'perm' => 'manage_services','icon' => 'clock'],
        ['route' => 'jobs.index',   'label' => 'Jobs',          'perm' => null,             'icon' => 'lightning'],
        ['route' => 'logs.index',   'label' => 'Logs',          'perm' => 'view_logs',      'icon' => 'document'],
        ['route' => 'audit-logs.index','label' => 'Audit Logs', 'perm' => 'view_audit_logs','icon' => 'eye'],
        ['route' => 'users.index',  'label' => 'Users',         'perm' => 'manage_users',   'icon' => 'users'],
        ['route' => 'settings.index','label' => 'Settings',     'perm' => 'manage_settings','icon' => 'adjustments'],
    ];
    $user = auth()->user();
@endphp

<aside
    class="fixed inset-y-0 left-0 z-40 w-64 transform bg-navy-900 transition-transform duration-200 ease-in-out lg:translate-x-0"
    :class="sidebarOpen ? 'translate-x-0' : '-translate-x-full'"
    x-cloak
>
    <div class="flex h-16 items-center gap-3 border-b border-white/10 px-5">
        <div class="flex h-9 w-9 items-center justify-center rounded-lg bg-brand-600">
            <svg class="h-5 w-5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M5 12h14M5 12a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v4a2 2 0 01-2 2M5 12a2 2 0 00-2 2v4a2 2 0 002 2h14a2 2 0 002-2v-4a2 2 0 00-2-2"/>
            </svg>
        </div>
        <div>
            <p class="text-sm font-semibold text-white leading-tight">LibreStack</p>
            <p class="text-[11px] text-brand-300 leading-tight">Control Panel</p>
        </div>
    </div>

    <nav class="flex h-[calc(100%-4rem)] flex-col overflow-y-auto px-3 py-4">
        <div class="space-y-1">
            @foreach ($nav as $item)
                @continue($item['perm'] && $user && ! $user->hasPermission($item['perm']))
                @php $active = request()->routeIs(str_replace('.index', '', $item['route']).'*'); @endphp
                <a href="{{ route($item['route']) }}"
                   class="group flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition
                          {{ $active ? 'bg-brand-600 text-white shadow-sm' : 'text-slate-300 hover:bg-white/5 hover:text-white' }}">
                    <span class="text-current">@include('partials.icons', ['name' => $item['icon']])</span>
                    {{ $item['label'] }}
                </a>
            @endforeach
        </div>

        <div class="mt-auto border-t border-white/10 pt-4">
            <a href="{{ route('profile.edit') }}"
               class="flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium text-slate-300 hover:bg-white/5 hover:text-white">
                <span>@include('partials.icons', ['name' => 'user'])</span>
                My Account
            </a>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit"
                        class="flex w-full items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium text-slate-300 hover:bg-red-500/10 hover:text-red-300">
                    <span>@include('partials.icons', ['name' => 'logout'])</span>
                    Sign out
                </button>
            </form>
        </div>
    </nav>
</aside>
