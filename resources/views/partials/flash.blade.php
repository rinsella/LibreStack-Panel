@php
    $flashes = [
        'success' => session('success'),
        'error'   => session('error'),
        'warning' => session('warning'),
        'info'    => session('info'),
    ];
    $styles = [
        'success' => 'border-emerald-500/40 bg-emerald-50 text-emerald-800',
        'error'   => 'border-red-500/40 bg-red-50 text-red-800',
        'warning' => 'border-amber-500/40 bg-amber-50 text-amber-800',
        'info'    => 'border-brand-500/40 bg-brand-50 text-brand-800',
    ];
@endphp

@if (collect($flashes)->filter()->isNotEmpty() || $errors->any())
    <div class="fixed right-4 top-4 z-50 w-full max-w-sm space-y-3"
         x-data="{ items: {{ $errors->any() || collect($flashes)->filter()->isNotEmpty() ? 'true' : 'false' }} }">
        @foreach ($flashes as $type => $message)
            @if ($message)
                <div x-data="{ show: true }" x-show="show" x-init="setTimeout(() => show = false, 6000)"
                     x-transition.opacity
                     class="flex items-start gap-3 rounded-xl border px-4 py-3 shadow-lg shadow-slate-900/5 {{ $styles[$type] }}">
                    <span class="mt-0.5">@include('partials.icons', ['name' => $type === 'success' ? 'check' : ($type === 'error' ? 'x' : 'warning'), 'class' => 'h-5 w-5'])</span>
                    <p class="flex-1 text-sm font-medium">{{ $message }}</p>
                    <button type="button" @click="show = false" class="text-current/60 hover:text-current">
                        @include('partials.icons', ['name' => 'x', 'class' => 'h-4 w-4'])
                    </button>
                </div>
            @endif
        @endforeach

        @if ($errors->any())
            <div x-data="{ show: true }" x-show="show" x-transition.opacity
                 class="rounded-xl border border-red-500/40 bg-red-50 px-4 py-3 text-red-800 shadow-lg">
                <div class="flex items-center justify-between">
                    <p class="text-sm font-semibold">Please fix the following:</p>
                    <button type="button" @click="show = false">@include('partials.icons', ['name' => 'x', 'class' => 'h-4 w-4'])</button>
                </div>
                <ul class="mt-2 list-disc space-y-1 pl-5 text-xs">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif
    </div>
@endif
