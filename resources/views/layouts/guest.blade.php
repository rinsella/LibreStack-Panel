<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Welcome') &middot; LibreStack Panel</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="h-full bg-navy-950 text-slate-100 antialiased">
    <div class="flex min-h-full items-center justify-center px-4 py-12">
        <div class="w-full max-w-md">
            <div class="mb-8 text-center">
                <div class="mx-auto mb-4 flex h-14 w-14 items-center justify-center rounded-2xl bg-brand-600 shadow-lg shadow-brand-600/30">
                    <svg class="h-8 w-8 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M5 12h14M5 12a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v4a2 2 0 01-2 2M5 12a2 2 0 00-2 2v4a2 2 0 002 2h14a2 2 0 002-2v-4a2 2 0 00-2-2"/>
                    </svg>
                </div>
                <h1 class="text-2xl font-bold text-white">LibreStack Panel</h1>
                <p class="mt-1 text-sm text-slate-400">Free forever &middot; Open source hosting control panel</p>
            </div>

            @include('partials.flash')

            @yield('content')
        </div>
    </div>
</body>
</html>
