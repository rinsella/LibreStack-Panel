<form method="POST" action="{{ $action }}" class="max-w-2xl space-y-6">
    @csrf
    @if ($method !== 'POST')@method($method)@endif
    <x-card title="Cron job">
        <div class="space-y-5">
            <div>
                <label class="ls-label" for="name">Name</label>
                <input class="ls-input" id="name" name="name" value="{{ old('name', $job->name) }}" required />
            </div>
            <div>
                <label class="ls-label" for="schedule">Schedule (cron expression)</label>
                <input class="ls-input font-mono" id="schedule" name="schedule" value="{{ old('schedule', $job->schedule ?: '0 3 * * *') }}" required />
                <p class="ls-help">Five fields: minute hour day month weekday. Example: <code>0 3 * * *</code> runs daily at 03:00.</p>
            </div>
            <div>
                <label class="ls-label" for="command">Command</label>
                <textarea class="ls-textarea" id="command" name="command" required>{{ old('command', $job->command) }}</textarea>
                <p class="ls-help">Avoid destructive commands. Dangerous patterns are flagged in the list.</p>
            </div>
            <label class="flex items-center gap-2 text-sm text-slate-600">
                <input type="checkbox" name="enabled" value="1" {{ old('enabled', $job->enabled ?? true) ? 'checked' : '' }} class="rounded border-slate-300 text-brand-600" />
                Enabled
            </label>
        </div>
    </x-card>
    <div class="flex justify-end gap-3">
        <a href="{{ route('cron.index') }}" class="ls-btn ls-btn-secondary">Cancel</a>
        <button class="ls-btn ls-btn-primary">Save cron job</button>
    </div>
</form>
