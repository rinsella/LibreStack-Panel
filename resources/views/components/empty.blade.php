@props(['icon' => 'document', 'title' => 'Nothing here yet', 'message' => null])
<div class="flex flex-col items-center justify-center px-6 py-12 text-center">
    <div class="mb-4 flex h-14 w-14 items-center justify-center rounded-2xl bg-slate-100 text-slate-400">
        @include('partials.icons', ['name' => $icon, 'class' => 'h-7 w-7'])
    </div>
    <h3 class="text-sm font-semibold text-slate-900">{{ $title }}</h3>
    @if ($message)<p class="mt-1 max-w-sm text-sm text-slate-500">{{ $message }}</p>@endif
    @isset($action)<div class="mt-4">{{ $action }}</div>@endisset
</div>
