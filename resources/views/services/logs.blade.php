@extends('layouts.app')
@section('title', 'Service logs')
@section('subheading', $service)

@section('content')
<div class="mb-4"><a href="{{ route('services.index') }}" class="text-sm text-brand-700">&larr; Back to services</a></div>
<x-card padding="p-0">
    <pre class="max-h-[70vh] overflow-auto rounded-2xl bg-navy-950 p-4 text-xs leading-relaxed text-slate-200">{{ $logs }}</pre>
</x-card>
@endsection
