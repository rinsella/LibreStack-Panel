@props(['title' => null, 'subtitle' => null, 'padding' => 'p-5 sm:p-6'])
<div {{ $attributes->merge(['class' => 'rounded-2xl border border-slate-200 bg-white shadow-sm']) }}>
    @if ($title || isset($actions))
        <div class="flex items-center justify-between gap-4 border-b border-slate-100 px-5 py-4 sm:px-6">
            <div>
                @if ($title)<h3 class="text-base font-semibold text-slate-900">{{ $title }}</h3>@endif
                @if ($subtitle)<p class="mt-0.5 text-sm text-slate-500">{{ $subtitle }}</p>@endif
            </div>
            @isset($actions)<div class="flex items-center gap-2">{{ $actions }}</div>@endisset
        </div>
    @endif
    <div class="{{ $padding }}">
        {{ $slot }}
    </div>
</div>
