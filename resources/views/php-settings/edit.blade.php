@extends('layouts.app')
@section('title', 'PHP settings — ' . $website->domain)
@section('subheading', 'Tune php.ini limits for this site (applied to its PHP-FPM pool).')

@section('content')
<form method="POST" action="{{ route('php-settings.update', $website) }}" class="max-w-2xl space-y-6">
    @csrf
    @method('PUT')

    <x-card title="PHP settings" subtitle="PHP {{ $website->php_version ?? config('librestack.default_php') }} · {{ $website->domain }}">
        <div class="space-y-5">
            @foreach ($definitions as $key => $def)
                <div>
                    <label class="ls-label" for="{{ $key }}">{{ $def['label'] }}</label>
                    <input class="ls-input font-mono"
                           id="{{ $key }}"
                           name="{{ $key }}"
                           value="{{ old($key, $values[$key] ?? ($def['default'] ?? '')) }}"
                           required />
                    <p class="ls-help">
                        <code>{{ $key }}</code>
                        @if (($def['type'] ?? 'size') === 'size')
                            — size such as <code>64M</code> or <code>1G</code>.
                        @else
                            — whole number.
                        @endif
                        Default: <code>{{ $def['default'] ?? '—' }}</code>.
                    </p>
                    @error($key)
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>
            @endforeach
        </div>
    </x-card>

    <div class="rounded-xl bg-amber-50 px-4 py-3 text-sm text-amber-800 ring-1 ring-amber-600/20">
        Changes are applied by rewriting this site's PHP-FPM pool and reloading PHP-FPM
        in the background. They take effect within a few seconds — watch
        <a href="{{ route('jobs.index') }}" class="font-medium underline">Jobs</a> for the result.
    </div>

    <div class="flex justify-end gap-3">
        <a href="{{ route('websites.show', $website) }}" class="ls-btn ls-btn-secondary">Cancel</a>
        <button class="ls-btn ls-btn-primary">Save PHP settings</button>
    </div>
</form>
@endsection
