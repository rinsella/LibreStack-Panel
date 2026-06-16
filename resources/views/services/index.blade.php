@extends('layouts.app')
@section('title', 'Services')
@section('subheading', 'Control core system services (allowlisted).')

@section('content')
<x-card padding="p-0">
    <div class="overflow-x-auto">
        <table class="ls-table">
            <thead><tr><th>Service</th><th>Status</th><th class="text-right">Actions</th></tr></thead>
            <tbody>
                @foreach ($services as $service)
                    <tr>
                        <td class="font-mono font-medium text-slate-800">{{ $service['name'] }}</td>
                        <td><x-badge :status="$service['status'] === 'active' ? 'active' : ($service['status'] === 'unknown' ? 'unknown' : 'failed')">{{ $service['status'] }}</x-badge></td>
                        <td class="text-right">
                            <div class="flex items-center justify-end gap-1">
                                @foreach (['start' => 'Start', 'restart' => 'Restart', 'reload' => 'Reload', 'stop' => 'Stop'] as $action => $label)
                                    <form method="POST" action="{{ route('services.action', $service['name']) }}" class="inline"
                                          @if($action === 'stop') onsubmit="return confirm('Stop {{ $service['name'] }}?')" @endif>
                                        @csrf
                                        <input type="hidden" name="action" value="{{ $action }}" />
                                        <button class="rounded px-2 py-1 text-sm {{ $action === 'stop' ? 'text-red-600 hover:bg-red-50' : 'text-slate-600 hover:bg-slate-100' }}">{{ $label }}</button>
                                    </form>
                                @endforeach
                                <a href="{{ route('services.logs', $service['name']) }}" class="rounded px-2 py-1 text-sm text-brand-700 hover:bg-brand-50">Logs</a>
                            </div>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</x-card>
@endsection
