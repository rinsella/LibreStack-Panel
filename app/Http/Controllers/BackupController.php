<?php

namespace App\Http\Controllers;

use App\Models\Backup;
use App\Models\BackupSchedule;
use App\Models\Website;
use App\Services\Backup\BackupService;
use App\Support\Audit;
use App\Support\JobRunner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class BackupController extends Controller
{
    public function __construct(protected BackupService $backups)
    {
    }

    public function index()
    {
        return view('backups.index', [
            'backups'   => Backup::with('website')->latest()->paginate(15),
            'schedules' => BackupSchedule::with('website')->latest()->get(),
            'websites'  => Website::orderBy('domain')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'website_id' => ['required', 'exists:websites,id'],
            'type'       => ['required', Rule::in(['files', 'database', 'full'])],
        ]);

        $website = Website::findOrFail($data['website_id']);

        $job = JobRunner::run('backup.create', ['domain' => $website->domain, 'type' => $data['type']], function ($job) use ($website, $data) {
            $backup = $this->backups->create($website, $data['type']);
            $job->update(['payload' => array_merge($job->payload ?? [], ['backup_id' => $backup->id])]);

            if ($backup->status !== 'success') {
                throw new \RuntimeException('Backup failed.');
            }

            return "Backup created for {$website->domain} ({$data['type']}).";
        });

        Audit::log('backup.created', 'website', (string) $website->id, ['type' => $data['type']]);

        return back()->with($job->status === 'success' ? 'success' : 'error', $job->message);
    }

    public function restore(Backup $backup): RedirectResponse
    {
        $ok = $this->backups->restore($backup);

        Audit::log('backup.restored', 'backup', (string) $backup->id, ['domain' => $backup->domain]);

        return back()->with($ok ? 'success' : 'error', $ok ? 'Backup restored.' : 'Restore failed.');
    }

    public function download(Backup $backup): BinaryFileResponse|RedirectResponse
    {
        if (! $backup->path || ! is_file($backup->path)) {
            return back()->with('error', 'Backup file not found on disk.');
        }

        Audit::log('backup.downloaded', 'backup', (string) $backup->id);

        return response()->download($backup->path);
    }

    public function destroy(Backup $backup): RedirectResponse
    {
        $this->backups->delete($backup);

        Audit::log('backup.deleted', 'backup', (string) $backup->id);

        return back()->with('success', 'Backup deleted.');
    }

    public function storeSchedule(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'website_id' => ['required', 'exists:websites,id'],
            'type'       => ['required', Rule::in(['files', 'database', 'full'])],
            'frequency'  => ['required', Rule::in(['daily', 'weekly', 'monthly'])],
            'retention'  => ['required', 'integer', 'min:1', 'max:90'],
        ]);

        BackupSchedule::create($data + ['enabled' => true]);

        Audit::log('backup_schedule.created', 'website', (string) $data['website_id'], $data);

        return back()->with('success', 'Backup schedule saved.');
    }

    public function destroySchedule(BackupSchedule $schedule): RedirectResponse
    {
        $schedule->delete();

        Audit::log('backup_schedule.deleted', 'backup_schedule', (string) $schedule->id);

        return back()->with('success', 'Backup schedule removed.');
    }
}
