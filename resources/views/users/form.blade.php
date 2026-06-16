@php $selectedRoles = old('roles', $user->roles->pluck('id')->all()); @endphp
<form method="POST" action="{{ $action }}" class="max-w-2xl space-y-6">
    @csrf
    @if ($method !== 'POST')@method($method)@endif
    <x-card title="Account">
        <div class="grid gap-5 sm:grid-cols-2">
            <div>
                <label class="ls-label" for="name">Name</label>
                <input class="ls-input" id="name" name="name" value="{{ old('name', $user->name) }}" required />
            </div>
            <div>
                <label class="ls-label" for="email">Email</label>
                <input class="ls-input" id="email" name="email" type="email" value="{{ old('email', $user->email) }}" required />
            </div>
            <div>
                <label class="ls-label" for="password">Password {{ $isNew ? '' : '(leave blank to keep)' }}</label>
                <input class="ls-input" id="password" name="password" type="password" {{ $isNew ? 'required' : '' }} />
            </div>
            <div>
                <label class="ls-label" for="password_confirmation">Confirm password</label>
                <input class="ls-input" id="password_confirmation" name="password_confirmation" type="password" {{ $isNew ? 'required' : '' }} />
            </div>
            <div>
                <label class="ls-label" for="status">Status</label>
                <select class="ls-select" id="status" name="status">
                    <option value="active" @selected(old('status', $user->status ?? 'active') === 'active')>Active</option>
                    <option value="suspended" @selected(old('status', $user->status ?? '') === 'suspended')>Suspended</option>
                </select>
            </div>
            <div>
                <label class="ls-label" for="system_username">System username (optional)</label>
                <input class="ls-input" id="system_username" name="system_username" value="{{ old('system_username', $user->system_username) }}" placeholder="webuser" />
            </div>
        </div>
    </x-card>

    <x-card title="Roles">
        <div class="grid gap-3 sm:grid-cols-2">
            @foreach ($roles as $role)
                <label class="flex items-start gap-3 rounded-xl border border-slate-200 p-3 hover:bg-slate-50">
                    <input type="checkbox" name="roles[]" value="{{ $role->id }}" @checked(in_array($role->id, $selectedRoles)) class="mt-1 rounded border-slate-300 text-brand-600" />
                    <span>
                        <span class="block text-sm font-medium text-slate-800">{{ $role->label }}</span>
                        <span class="block text-xs text-slate-400">{{ $role->description }}</span>
                    </span>
                </label>
            @endforeach
        </div>
    </x-card>

    <div class="flex justify-end gap-3">
        <a href="{{ route('users.index') }}" class="ls-btn ls-btn-secondary">Cancel</a>
        <button class="ls-btn ls-btn-primary">Save user</button>
    </div>
</form>
