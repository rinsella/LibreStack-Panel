<?php

namespace App\Http\Controllers;

use App\Models\Backup;
use App\Models\BackupSchedule;
use App\Models\Website;
use App\Jobs\CreateBackupJob;
use App\Jobs\RestoreBackupJob;
use App\Services\Backup\BackupService;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class BackupController extends Controller
{
    public function __construct(protected BackupService $backups)
    {
    }

    public function index(Request $request)
    {
        $user = $request->user();
        $ownedWebsites = Website::query()
            ->when(! $user->isAdmin(), fn ($q) => $q->where('user_id', $user->id));

        return view('backups.index', [
            'backups'   => Backup::with('website')
                ->when(! $user->isAdmin(), function ($q) use ($user) {
                    $q->where('created_by', $user->id)
                        ->orWhereHas('website', fn ($w) => $w->where('user_id', $user->id));
                })
                ->latest()->paginate(15),
            'schedules' => BackupSchedule::with('website')->latest()->get(),
            'websites'  => $ownedWebsites->orderBy('domain')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'website_id' => ['required', 'exists:websites,id'],
            'type'       => ['required', Rule::in(['files', 'database', 'full'])],
        ]);

        $website = Website::findOrFail($data['website_id']);
        $this->authorize('update', $website);

        CreateBackupJob::dispatch($website->id, $data['type'], $request->user()->id);

        Audit::log('backup.created', 'website', (string) $website->id, ['type' => $data['type']]);

        return back()->with('success', "Backup queued for {$website->domain} ({$data['type']}).");
    }

    public function restore(Backup $backup): RedirectResponse
    {
        $this->authorize('restore', $backup);

        RestoreBackupJob::dispatch($backup->id);

        Audit::log('backup.restored', 'backup', (string) $backup->id, ['domain' => $backup->domain]);

        return back()->with('success', 'Backup restore queued.');
    }

    public function download(Backup $backup): BinaryFileResponse|RedirectResponse
    {
        $this->authorize('view', $backup);

        if (! $backup->path || ! is_file($backup->path)) {
            return back()->with('error', 'Backup file not found on disk.');
        }

        Audit::log('backup.downloaded', 'backup', (string) $backup->id);

        return response()->download($backup->path);
    }

    public function destroy(Backup $backup): RedirectResponse
    {
        $this->authorize('delete', $backup);

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

        $this->authorize('update', Website::findOrFail($data['website_id']));

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
