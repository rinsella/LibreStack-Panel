@extends('layouts.app')
@section('title', 'Firewall')
@section('subheading', 'Manage UFW rules. Be careful not to lock yourself out.')

@section('content')
<div class="grid gap-6 lg:grid-cols-3">
    <div class="space-y-6 lg:col-span-2">
        <x-card title="Active rules" padding="p-0">
            <div class="overflow-x-auto">
                <table class="ls-table">
                    <thead><tr><th>#</th><th>Rule</th><th class="text-right">Action</th></tr></thead>
                    <tbody>
                        @forelse ($rules as $rule)
                            <tr>
                                <td class="text-slate-400">{{ $rule['number'] }}</td>
                                <td class="font-mono text-sm">{{ $rule['rule'] }}</td>
                                <td class="text-right">
                                    <x-confirm :action="route('firewall.destroy', $rule['number'])" method="DELETE" title="Delete rule #{{ $rule['number'] }}?" message="Make sure you are not removing SSH access." trigger="Delete" triggerClass="rounded px-2 py-1 text-sm text-red-600 hover:bg-red-50" />
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="3"><x-empty icon="shield" title="No rules / UFW unavailable" message="UFW may be disabled or system commands are off in dev mode." /></td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-card>

        <x-card title="Status output">
            <pre class="max-h-72 overflow-auto rounded-xl bg-navy-950 p-4 text-xs text-slate-200">{{ $status }}</pre>
        </x-card>
    </div>

    <div class="space-y-6">
        <x-card title="Add rule">
            <form method="POST" action="{{ route('firewall.store') }}" class="space-y-3">
                @csrf
                <div class="grid grid-cols-2 gap-2">
                    <div><label class="ls-label">Port</label><input class="ls-input" name="port" type="number" min="1" max="65535" required></div>
                    <div><label class="ls-label">Protocol</label><select class="ls-select" name="proto"><option value="tcp">TCP</option><option value="udp">UDP</option></select></div>
                </div>
                <div><label class="ls-label">Policy</label><select class="ls-select" name="policy"><option value="allow">Allow</option><option value="deny">Deny</option></select></div>
                <button class="ls-btn ls-btn-primary w-full justify-center">Apply rule</button>
            </form>
            <div class="mt-4 border-t border-slate-100 pt-3">
                <p class="mb-2 text-xs font-semibold uppercase text-slate-400">Quick presets</p>
                <div class="flex flex-wrap gap-2">
                    @foreach ($presets as $preset)
                        <form method="POST" action="{{ route('firewall.store') }}">
                            @csrf
                            <input type="hidden" name="port" value="{{ $preset['port'] }}">
                            <input type="hidden" name="proto" value="{{ $preset['proto'] }}">
                            <input type="hidden" name="policy" value="allow">
                            <button class="rounded-lg border px-2.5 py-1 text-xs {{ $preset['danger'] ? 'border-amber-300 text-amber-700' : 'border-slate-200 text-slate-600 hover:bg-slate-50' }}"
                                    @if($preset['danger']) onsubmit="return confirm('Exposing this port can be risky. Continue?')" @endif>
                                Allow {{ $preset['label'] }}
                            </button>
                        </form>
                    @endforeach
                </div>
            </div>
        </x-card>

        <x-card title="Firewall power">
            <div class="flex gap-2">
                <form method="POST" action="{{ route('firewall.toggle') }}" class="flex-1">@csrf<input type="hidden" name="enable" value="1"><button class="ls-btn ls-btn-primary w-full justify-center">Enable UFW</button></form>
                <form method="POST" action="{{ route('firewall.toggle') }}" class="flex-1" onsubmit="return confirm('Disabling the firewall lowers security. Continue?')">@csrf<input type="hidden" name="enable" value="0"><button class="ls-btn ls-btn-danger w-full justify-center">Disable</button></form>
            </div>
            <p class="ls-help mt-3">Always keep your SSH port allowed before enabling UFW.</p>
        </x-card>
    </div>
</div>
@endsection
