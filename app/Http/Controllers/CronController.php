<?php

namespace App\Http\Controllers;

use App\Models\CronJob;
use App\Services\System\CronService;
use App\Support\Audit;
use App\Support\Validators;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class CronController extends Controller
{
    public function __construct(protected CronService $cron)
    {
    }

    public function index(Request $request)
    {
        $user = $request->user();

        return view('cron.index', [
            'jobs' => CronJob::with('user')
                ->when(! $user->isAdmin(), fn ($q) => $q->where('user_id', $user->id))
                ->latest()->get(),
        ]);
    }

    public function create()
    {
        return view('cron.create', ['job' => new CronJob()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', CronJob::class);

        $data = $this->validateCron($request);

        $job = CronJob::create($data + [
            'user_id'         => $request->user()->id,
            'system_username' => $request->user()->system_username,
            'enabled'         => $request->boolean('enabled', true),
        ]);

        $this->cron->sync();

        Audit::log('cron.created', 'cron_job', (string) $job->id, ['name' => $job->name]);

        return redirect()->route('cron.index')->with('success', 'Cron job created.');
    }

    public function edit(CronJob $cron)
    {
        $this->authorize('update', $cron);

        return view('cron.edit', ['job' => $cron]);
    }

    public function update(Request $request, CronJob $cron): RedirectResponse
    {
        $this->authorize('update', $cron);

        $data = $this->validateCron($request);

        $cron->update($data + ['enabled' => $request->boolean('enabled')]);
        $this->cron->sync();

        Audit::log('cron.updated', 'cron_job', (string) $cron->id, ['name' => $cron->name]);

        return redirect()->route('cron.index')->with('success', 'Cron job updated.');
    }

    public function toggle(CronJob $cron): RedirectResponse
    {
        $this->authorize('update', $cron);

        $cron->update(['enabled' => ! $cron->enabled]);
        $this->cron->sync();

        Audit::log('cron.toggled', 'cron_job', (string) $cron->id, ['enabled' => $cron->enabled]);

        return back()->with('success', $cron->enabled ? 'Cron job enabled.' : 'Cron job disabled.');
    }

    public function destroy(CronJob $cron): RedirectResponse
    {
        $this->authorize('delete', $cron);

        $cron->delete();
        $this->cron->sync();

        Audit::log('cron.deleted', 'cron_job', (string) $cron->id);

        return back()->with('success', 'Cron job deleted.');
    }

    protected function validateCron(Request $request): array
    {
        $data = $request->validate([
            'name'     => ['required', 'string', 'max:255'],
            'schedule' => ['required', 'string', 'max:100'],
            'command'  => ['required', 'string', 'max:1000'],
        ]);

        if (! Validators::isValidCronSchedule($data['schedule'])) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'schedule' => 'Invalid cron schedule (expecting 5 fields).',
            ]);
        }

        return $data;
    }
}
