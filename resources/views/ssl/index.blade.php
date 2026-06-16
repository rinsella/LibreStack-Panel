@extends('layouts.app')
@section('title', 'SSL certificates')
@section('subheading', "Issue and renew free Let's Encrypt certificates with certbot.")

@section('content')
<x-card padding="p-0">
    <div class="overflow-x-auto">
        <table class="ls-table">
            <thead><tr><th>Domain</th><th>Status</th><th>Issuer</th><th>Expires</th><th class="text-right">Actions</th></tr></thead>
            <tbody>
                @forelse ($websites as $website)
                    @php $cert = $website->sslCertificate->first(); @endphp
                    <tr x-data="{ open: false }">
                        <td class="font-medium text-slate-800">{{ $website->domain }}</td>
                        <td>
                            @if ($cert)<x-badge :status="$cert->status" />
                            @else<span class="text-xs text-slate-400">no certificate</span>@endif
                        </td>
                        <td>{{ $cert->issuer ?? '—' }}</td>
                        <td>
                            @if ($cert?->expires_at)
                                <span class="{{ $cert->expires_at->isPast() ? 'text-red-600' : 'text-slate-600' }}">{{ $cert->expires_at->format('Y-m-d') }}</span>
                            @else — @endif
                        </td>
                        <td class="text-right">
                            <div class="flex items-center justify-end gap-1">
                                @if ($cert && $cert->status === 'active')
                                    <form method="POST" action="{{ route('ssl.renew', $website) }}" class="inline">
                                        @csrf
                                        <button class="rounded-lg px-2.5 py-1.5 text-sm text-slate-600 hover:bg-slate-100">Renew</button>
                                    </form>
                                    <x-confirm :action="route('ssl.destroy', $website)" method="DELETE" title="Remove SSL for {{ $website->domain }}?" trigger="Remove" />
                                @else
                                    <button @click="open = !open" class="ls-btn ls-btn-primary !px-3 !py-1.5">Issue SSL</button>
                                @endif
                            </div>
                            <div x-show="open" x-cloak class="mt-2">
                                <form method="POST" action="{{ route('ssl.issue', $website) }}" class="flex items-center justify-end gap-2">
                                    @csrf
                                    <input class="ls-input !w-56" name="email" type="email" value="{{ $sslEmail }}" placeholder="admin@example.com" required />
                                    <button class="ls-btn ls-btn-primary !px-3 !py-1.5">Confirm</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5"><x-empty icon="lock" title="No websites" message="Create a website to manage SSL." /></td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-card>
@endsection
