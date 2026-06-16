@extends('layouts.app')
@section('title', 'Settings')
@section('subheading', 'Configure the panel. Secrets are encrypted at rest and masked.')

@section('content')
<form method="POST" action="{{ route('settings.update') }}" class="max-w-3xl space-y-6">
    @csrf
    @method('PUT')

    <x-card title="General">
        <div class="grid gap-5 sm:grid-cols-2">
            <div><label class="ls-label" for="panel_name">Panel name</label><input class="ls-input" id="panel_name" name="panel_name" value="{{ old('panel_name', $settings['panel_name']) }}" required></div>
            <div><label class="ls-label" for="panel_url">Panel URL</label><input class="ls-input" id="panel_url" name="panel_url" value="{{ old('panel_url', $settings['panel_url']) }}" placeholder="http://server-ip:8080"></div>
            <div><label class="ls-label" for="admin_email">Admin email</label><input class="ls-input" id="admin_email" name="admin_email" type="email" value="{{ old('admin_email', $settings['admin_email']) }}"></div>
            <div><label class="ls-label" for="ssl_email">SSL email (Let's Encrypt)</label><input class="ls-input" id="ssl_email" name="ssl_email" type="email" value="{{ old('ssl_email', $settings['ssl_email']) }}"></div>
            <div>
                <label class="ls-label" for="default_php">Default PHP version</label>
                <select class="ls-select" id="default_php" name="default_php">
                    @foreach ($phpVersions as $v)<option value="{{ $v }}" @selected($settings['default_php'] === $v)>PHP {{ $v }}</option>@endforeach
                </select>
            </div>
            <div>
                <label class="ls-label" for="theme_mode">Theme</label>
                <select class="ls-select" id="theme_mode" name="theme_mode">
                    <option value="dark" @selected($settings['theme_mode'] === 'dark')>Dark sidebar</option>
                    <option value="light" @selected($settings['theme_mode'] === 'light')>Light</option>
                </select>
            </div>
            <div class="sm:col-span-2"><label class="ls-label" for="backup_path">Backup path</label><input class="ls-input font-mono" id="backup_path" name="backup_path" value="{{ old('backup_path', $settings['backup_path']) }}"></div>
        </div>
    </x-card>

    <x-card title="Database admin" subtitle="Credentials used by the database manager (password encrypted).">
        <div class="grid gap-5 sm:grid-cols-2">
            <div><label class="ls-label" for="db_admin_username">DB admin username</label><input class="ls-input" id="db_admin_username" name="db_admin_username" value="{{ old('db_admin_username', $settings['db_admin_username']) }}"></div>
            <div>
                <label class="ls-label" for="db_admin_password">DB admin password</label>
                <input class="ls-input" id="db_admin_password" name="db_admin_password" type="password" placeholder="{{ $hasDbPassword ? '•••••••• (unchanged)' : 'not set' }}">
                <p class="ls-help">Leave blank to keep the current value. Stored encrypted; never displayed.</p>
            </div>
        </div>
    </x-card>

    <div class="flex justify-end"><button class="ls-btn ls-btn-primary">Save settings</button></div>
</form>
@endsection
