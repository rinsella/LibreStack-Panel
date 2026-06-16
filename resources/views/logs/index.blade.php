@extends('layouts.app')
@section('title', 'Logs')
@section('subheading', 'View and search recent log output (bounded for safety).')

@section('content')
<x-card padding="p-0">
    <form method="GET" class="flex flex-col gap-3 border-b border-slate-100 p-4 sm:flex-row sm:items-center">
        <select class="ls-select sm:!w-64" name="source" onchange="this.form.submit()">
            @foreach ($sources as $key => $src)
                <option value="{{ $key }}" @selected($source === $key)>{{ $src['label'] }}</option>
            @endforeach
        </select>
        <input class="ls-input sm:!w-48" name="q" value="{{ $search }}" placeholder="Filter lines…" />
        <select class="ls-select sm:!w-32" name="lines">
            @foreach ([100, 200, 500, 1000] as $n)<option value="{{ $n }}" @selected($lines === $n)>{{ $n }} lines</option>@endforeach
        </select>
        <button class="ls-btn ls-btn-secondary">Apply</button>
        <a href="{{ route('logs.download', ['source' => $source]) }}" class="ls-btn ls-btn-secondary">@include('partials.icons', ['name' => 'download', 'class' => 'h-4 w-4']) Download</a>
    </form>
    <pre class="max-h-[65vh] overflow-auto bg-navy-950 p-4 text-xs leading-relaxed text-slate-200">{{ $content }}</pre>
</x-card>
@endsection
