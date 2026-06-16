<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Dashboard') &middot; {{ \App\Models\Setting::get('panel_name', 'LibreStack Panel') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="h-full bg-slate-100 text-slate-800 antialiased">
<div x-data="{ sidebarOpen: false }" class="min-h-full">

    {{-- Sidebar --}}
    @include('partials.sidebar')

    {{-- Mobile overlay --}}
    <div x-show="sidebarOpen" x-cloak @click="sidebarOpen = false"
         class="fixed inset-0 z-30 bg-black/50 lg:hidden"></div>

    <div class="lg:pl-64">
        @include('partials.topbar')

        <main class="p-4 sm:p-6 lg:p-8">
            @include('partials.flash')

            <div class="mb-6">
                <h1 class="text-2xl font-semibold text-slate-900">@yield('heading', View::yieldContent('title'))</h1>
                @hasSection('subheading')
                    <p class="mt-1 text-sm text-slate-500">@yield('subheading')</p>
                @endif
            </div>

            @yield('content')
        </main>
    </div>
</div>
</body>
</html>
