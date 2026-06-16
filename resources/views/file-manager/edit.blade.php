@extends('layouts.app')
@section('title', 'Edit file')
@section('subheading', $relative)

@section('content')
@if ($error)
    <x-card><p class="text-sm text-red-600">{{ $error }}</p></x-card>
@else
    <form method="POST" action="{{ route('file-manager.save', ['website' => $website->id]) }}" class="space-y-4">
        @csrf
        @method('PUT')
        <input type="hidden" name="file" value="{{ $relative }}" />
        <x-card padding="p-0">
            <textarea name="content" class="h-[60vh] w-full resize-none border-0 bg-navy-950 p-4 font-mono text-sm text-slate-100 focus:outline-none" spellcheck="false">{{ $content }}</textarea>
        </x-card>
        <div class="flex justify-end gap-3">
            <a href="{{ route('file-manager.index', ['website' => $website->id, 'path' => trim(dirname($relative), '.')]) }}" class="ls-btn ls-btn-secondary">Cancel</a>
            <button class="ls-btn ls-btn-primary">Save file</button>
        </div>
    </form>
@endif
@endsection
