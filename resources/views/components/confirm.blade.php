@props([
    'action',
    'method' => 'DELETE',
    'title' => 'Are you sure?',
    'message' => 'This action cannot be undone.',
    'confirm' => 'Delete',
    'tone' => 'danger',
    'trigger' => null,
    'triggerClass' => null,
])
@php
    $btn = $tone === 'danger'
        ? 'bg-red-600 hover:bg-red-700'
        : 'bg-brand-600 hover:bg-brand-700';
@endphp
<div x-data="{ open: false }" {{ $attributes->only('class') }}>
    <button type="button" @click="open = true"
            class="{{ $triggerClass ?? 'inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-sm font-medium text-red-600 hover:bg-red-50' }}">
        {{ $trigger ?? $confirm }}
    </button>

    <template x-teleport="body">
        <div x-show="open" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4">
            <div x-show="open" x-transition.opacity @click="open = false" class="absolute inset-0 bg-slate-900/50"></div>
            <div x-show="open" x-transition
                 class="relative w-full max-w-md rounded-2xl bg-white p-6 shadow-xl">
                <div class="flex items-start gap-4">
                    <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-red-100 text-red-600">
                        @include('partials.icons', ['name' => 'warning', 'class' => 'h-5 w-5'])
                    </div>
                    <div class="flex-1">
                        <h3 class="text-base font-semibold text-slate-900">{{ $title }}</h3>
                        <p class="mt-1 text-sm text-slate-500">{{ $message }}</p>
                        @isset($fields)<div class="mt-3 space-y-2">{{ $fields }}</div>@endisset
                    </div>
                </div>
                <div class="mt-6 flex justify-end gap-2">
                    <button type="button" @click="open = false"
                            class="rounded-lg px-4 py-2 text-sm font-medium text-slate-600 hover:bg-slate-100">Cancel</button>
                    <form method="POST" action="{{ $action }}">
                        @csrf
                        @if (strtoupper($method) !== 'POST')@method($method)@endif
                        {{ $slot }}
                        <button type="submit" class="rounded-lg px-4 py-2 text-sm font-semibold text-white {{ $btn }}">{{ $confirm }}</button>
                    </form>
                </div>
            </div>
        </div>
    </template>
</div>
