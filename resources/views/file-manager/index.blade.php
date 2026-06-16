@extends('layouts.app')
@section('title', 'File manager')
@section('subheading', 'Browse files within your website directories. Access is sandboxed.')

@php use App\Support\Format; @endphp

@section('content')
<x-card padding="p-0" x-data="{ showNew: false }">
    {{-- Toolbar --}}
    <div class="flex flex-col gap-3 border-b border-slate-100 p-4 sm:flex-row sm:items-center sm:justify-between">
        <form method="GET" class="flex items-center gap-2">
            <select class="ls-select !w-auto" name="website" onchange="this.form.submit()">
                @foreach ($websites as $w)
                    <option value="{{ $w->id }}" @selected($website && $website->id === $w->id)>{{ $w->domain }}</option>
                @endforeach
            </select>
        </form>
        @if ($website)
            <div class="flex items-center gap-2">
                <button @click="showNew = !showNew" class="ls-btn ls-btn-secondary">@include('partials.icons', ['name' => 'plus', 'class' => 'h-4 w-4']) New</button>
                <form method="POST" action="{{ route('file-manager.upload', ['website' => $website->id]) }}" enctype="multipart/form-data" class="flex items-center gap-2">
                    @csrf
                    <input type="hidden" name="path" value="{{ $relative }}" />
                    <input type="file" name="file" class="text-xs" onchange="this.form.submit()" />
                </form>
            </div>
        @endif
    </div>

    @if ($website)
        {{-- New file/folder forms --}}
        <div x-show="showNew" x-cloak class="grid gap-3 border-b border-slate-100 bg-slate-50 p-4 sm:grid-cols-2">
            <form method="POST" action="{{ route('file-manager.folder') }}" class="flex gap-2">
                @csrf
                <input type="hidden" name="website" value="{{ $website->id }}"><input type="hidden" name="path" value="{{ $relative }}">
                <input class="ls-input" name="name" placeholder="New folder name" required>
                <button class="ls-btn ls-btn-secondary">Folder</button>
            </form>
            <form method="POST" action="{{ route('file-manager.file') }}" class="flex gap-2">
                @csrf
                <input type="hidden" name="website" value="{{ $website->id }}"><input type="hidden" name="path" value="{{ $relative }}">
                <input class="ls-input" name="name" placeholder="New file name" required>
                <button class="ls-btn ls-btn-secondary">File</button>
            </form>
        </div>

        {{-- Breadcrumb --}}
        <div class="flex items-center gap-1 border-b border-slate-100 px-4 py-2 text-sm text-slate-500">
            <a href="{{ route('file-manager.index', ['website' => $website->id]) }}" class="hover:text-brand-700">root</a>
            @php $crumb = ''; @endphp
            @foreach (array_filter(explode('/', $relative)) as $part)
                @php $crumb = trim($crumb . '/' . $part, '/'); @endphp
                <span>/</span><a href="{{ route('file-manager.index', ['website' => $website->id, 'path' => $crumb]) }}" class="hover:text-brand-700">{{ $part }}</a>
            @endforeach
        </div>
    @endif

    @if ($error)
        <div class="p-4 text-sm text-red-600">{{ $error }}</div>
    @elseif (! $website)
        <x-empty icon="folder" title="No website selected" message="Choose a website to browse its files." />
    @else
        <div class="overflow-x-auto">
            <table class="ls-table">
                <thead><tr><th>Name</th><th>Size</th><th>Perms</th><th>Modified</th><th class="text-right">Actions</th></tr></thead>
                <tbody>
                    @if ($relative !== '')
                        <tr><td colspan="5"><a href="{{ route('file-manager.index', ['website' => $website->id, 'path' => trim(dirname($relative), '.')]) }}" class="text-sm text-brand-700">.. up one level</a></td></tr>
                    @endif
                    @forelse ($items as $item)
                        @php $itemPath = trim($relative . '/' . $item['name'], '/'); @endphp
                        <tr>
                            <td>
                                @if ($item['is_dir'])
                                    <a href="{{ route('file-manager.index', ['website' => $website->id, 'path' => $itemPath]) }}" class="flex items-center gap-2 font-medium text-slate-800">
                                        <span class="text-amber-500">@include('partials.icons', ['name' => 'folder', 'class' => 'h-4 w-4'])</span>{{ $item['name'] }}
                                    </a>
                                @else
                                    <span class="flex items-center gap-2 text-slate-700">
                                        <span class="text-slate-400">@include('partials.icons', ['name' => 'document', 'class' => 'h-4 w-4'])</span>{{ $item['name'] }}
                                    </span>
                                @endif
                            </td>
                            <td class="text-slate-500">{{ $item['is_dir'] ? '—' : Format::bytes($item['size']) }}</td>
                            <td class="font-mono text-xs text-slate-500">{{ $item['permissions'] }}</td>
                            <td class="text-slate-400">{{ $item['modified'] }}</td>
                            <td class="text-right">
                                <div class="flex items-center justify-end gap-1 text-sm">
                                    @if (! $item['is_dir'])
                                        <a href="{{ route('file-manager.edit', ['website' => $website->id, 'file' => $itemPath]) }}" class="rounded px-2 py-1 text-slate-600 hover:bg-slate-100">Edit</a>
                                        <a href="{{ route('file-manager.download', ['website' => $website->id, 'file' => $itemPath]) }}" class="rounded px-2 py-1 text-slate-600 hover:bg-slate-100">Download</a>
                                    @endif
                                    <form method="POST" action="{{ route('file-manager.zip') }}" class="inline">@csrf<input type="hidden" name="website" value="{{ $website->id }}"><input type="hidden" name="path" value="{{ $relative }}"><input type="hidden" name="file" value="{{ $itemPath }}"><button class="rounded px-2 py-1 text-slate-600 hover:bg-slate-100">Zip</button></form>
                                    <x-confirm :action="route('file-manager.destroy')" method="DELETE" title="Delete {{ $item['name'] }}?" trigger="Delete" triggerClass="rounded px-2 py-1 text-sm text-red-600 hover:bg-red-50">
                                        <input type="hidden" name="website" value="{{ $website->id }}"><input type="hidden" name="path" value="{{ $relative }}"><input type="hidden" name="file" value="{{ $itemPath }}">
                                    </x-confirm>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5"><x-empty icon="folder" title="Empty folder" /></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    @endif
</x-card>
@endsection
