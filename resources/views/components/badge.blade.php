@props(['status' => 'unknown'])
@php
    $s = strtolower(trim((string) $status));
    $map = [
        'active'    => 'bg-emerald-50 text-emerald-700 ring-emerald-600/20',
        'success'   => 'bg-emerald-50 text-emerald-700 ring-emerald-600/20',
        'running'   => 'bg-brand-50 text-brand-700 ring-brand-600/20',
        'queued'    => 'bg-amber-50 text-amber-700 ring-amber-600/20',
        'pending'   => 'bg-amber-50 text-amber-700 ring-amber-600/20',
        'inactive'  => 'bg-slate-100 text-slate-600 ring-slate-500/20',
        'unknown'   => 'bg-slate-100 text-slate-600 ring-slate-500/20',
        'suspended' => 'bg-amber-50 text-amber-700 ring-amber-600/20',
        'failed'    => 'bg-red-50 text-red-700 ring-red-600/20',
        'expired'   => 'bg-red-50 text-red-700 ring-red-600/20',
    ];
    $cls = $map[$s] ?? 'bg-slate-100 text-slate-600 ring-slate-500/20';
@endphp
<span {{ $attributes->merge(['class' => "inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-medium ring-1 ring-inset {$cls}"]) }}>
    <span class="h-1.5 w-1.5 rounded-full bg-current opacity-70"></span>
    {{ $slot->isEmpty() ? ucfirst($s) : $slot }}
</span>
