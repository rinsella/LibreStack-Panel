@php
    $user = auth()->user();
@endphp
<header class="sticky top-0 z-20 flex h-16 items-center gap-4 border-b border-slate-200 bg-white/90 px-4 backdrop-blur sm:px-6 lg:px-8">
    <button type="button" @click="sidebarOpen = true"
            class="rounded-lg p-2 text-slate-500 hover:bg-slate-100 lg:hidden">
        <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h16" />
        </svg>
    </button>

    <div class="flex flex-1 items-center gap-2">
        <span class="hidden text-sm font-medium text-slate-400 sm:inline">{{ \App\Models\Setting::get('panel_name', 'LibreStack Panel') }}</span>
    </div>

    <div class="flex items-center gap-3">
        <a href="{{ route('jobs.index') }}" class="rounded-lg p-2 text-slate-500 hover:bg-slate-100" title="Jobs">
            @include('partials.icons', ['name' => 'lightning', 'class' => 'h-5 w-5'])
        </a>

        <div x-data="{ open: false }" class="relative">
            <button type="button" @click="open = !open"
                    class="flex items-center gap-2 rounded-lg py-1.5 pl-1.5 pr-3 text-sm hover:bg-slate-100">
                <span class="flex h-8 w-8 items-center justify-center rounded-full bg-brand-600 text-sm font-semibold text-white">
                    {{ strtoupper(substr($user->name ?? 'U', 0, 1)) }}
                </span>
                <span class="hidden text-left sm:block">
                    <span class="block text-sm font-medium leading-tight text-slate-800">{{ $user->name ?? 'User' }}</span>
                    <span class="block text-xs leading-tight text-slate-400">{{ $user->roles->first()->label ?? 'Member' }}</span>
                </span>
            </button>

            <div x-show="open" x-cloak @click.outside="open = false" x-transition
                 class="absolute right-0 mt-2 w-48 rounded-xl border border-slate-200 bg-white py-1 shadow-lg">
                <a href="{{ route('profile.edit') }}" class="block px-4 py-2 text-sm text-slate-700 hover:bg-slate-50">My account</a>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="block w-full px-4 py-2 text-left text-sm text-red-600 hover:bg-red-50">Sign out</button>
                </form>
            </div>
        </div>
    </div>
</header>
