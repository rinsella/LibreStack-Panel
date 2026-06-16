<?php

namespace App\Http\Controllers;

use App\Models\FileOperation;
use App\Models\Website;
use App\Services\FileManager\FileManagerService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Secure web file manager. Every operation is scoped to a selected website's
 * base directory; the FileManagerService enforces realpath containment so path
 * traversal and access to system locations is impossible.
 */
class FileManagerController extends Controller
{
    public function __construct(protected FileManagerService $files)
    {
    }

    public function index(Request $request)
    {
        $user = $request->user();
        $websites = Website::query()
            ->when(! $user->isAdmin(), fn ($q) => $q->where('user_id', $user->id))
            ->orderBy('domain')->get();

        // If a specific website is requested, authorize it explicitly so that
        // attempting to browse another user's site is forbidden (not just empty).
        if ($request->query('website')) {
            $requested = Website::findOrFail((int) $request->query('website'));
            $this->authorize('view', $requested);
            $website = $requested;
        } else {
            $website = $websites->first();
        }

        $relative = (string) $request->query('path', '');

        $items = [];
        $error = null;
        $base = $website ? dirname($website->document_root) : null;

        if ($website && $base && is_dir($base)) {
            try {
                $items = $this->files->list($base, $relative);
            } catch (\Throwable $e) {
                $error = $e->getMessage();
            }
        } elseif ($website) {
            $error = 'Website directory does not exist yet on this host.';
        }

        return view('file-manager.index', [
            'websites' => $websites,
            'website'  => $website,
            'items'    => $items,
            'relative' => $relative,
            'error'    => $error,
        ]);
    }

    public function edit(Request $request)
    {
        [$website, $base] = $this->resolveBase($request, 'view');
        $relative = (string) $request->query('file', '');

        $content = '';
        $error = null;
        try {
            $content = $this->files->read($base, $relative);
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }

        return view('file-manager.edit', compact('website', 'relative', 'content', 'error'));
    }

    public function save(Request $request): RedirectResponse
    {
        [$website, $base] = $this->resolveBase($request);
        $data = $request->validate([
            'file'    => ['required', 'string'],
            'content' => ['nullable', 'string'],
        ]);

        $this->files->write($base, $data['file'], (string) $data['content']);
        $this->record($request, 'edit', $data['file']);

        return redirect()
            ->route('file-manager.index', ['website' => $website->id, 'path' => dirname($data['file'])])
            ->with('success', 'File saved.');
    }

    public function download(Request $request): BinaryFileResponse|RedirectResponse
    {
        [, $base] = $this->resolveBase($request, 'view');
        $relative = (string) $request->query('file', '');

        try {
            $path = $this->files->resolve($base, $relative);
            if (! is_file($path)) {
                return back()->with('error', 'File not found.');
            }
            $this->record($request, 'download', $relative);

            return response()->download($path);
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function upload(Request $request): RedirectResponse
    {
        [$website, $base] = $this->resolveBase($request);
        $request->validate([
            'path' => ['nullable', 'string'],
            'file' => ['required', 'file', 'max:102400'],
        ]);

        $relative = (string) $request->input('path', '');
        $name = $request->file('file')->getClientOriginalName();
        // Strip any directory components from the uploaded filename.
        $name = basename(str_replace('\\', '/', $name));

        $target = $this->files->resolve($base, trim($relative . '/' . $name, '/'));
        $request->file('file')->move(dirname($target), basename($target));

        $this->record($request, 'upload', $relative . '/' . $name);

        return $this->backTo($website, $relative)->with('success', 'File uploaded.');
    }

    public function makeFolder(Request $request): RedirectResponse
    {
        return $this->createEntry($request, 'folder');
    }

    public function makeFile(Request $request): RedirectResponse
    {
        return $this->createEntry($request, 'file');
    }

    public function rename(Request $request): RedirectResponse
    {
        [$website, $base] = $this->resolveBase($request);
        $data = $request->validate([
            'path' => ['nullable', 'string'],
            'from' => ['required', 'string'],
            'to'   => ['required', 'string', 'max:255'],
        ]);

        $dir = (string) $data['path'];
        $this->files->rename($base, $data['from'], trim($dir . '/' . basename($data['to']), '/'));
        $this->record($request, 'rename', $data['from'], $data['to']);

        return $this->backTo($website, $dir)->with('success', 'Renamed.');
    }

    public function chmod(Request $request): RedirectResponse
    {
        [$website, $base] = $this->resolveBase($request);
        $data = $request->validate([
            'path' => ['nullable', 'string'],
            'file' => ['required', 'string'],
            'mode' => ['required', 'regex:/^[0-7]{3,4}$/'],
        ]);

        $this->files->chmod($base, $data['file'], (int) octdec($data['mode']));
        $this->record($request, 'chmod', $data['file']);

        return $this->backTo($website, (string) $data['path'])->with('success', 'Permissions updated.');
    }

    public function zip(Request $request): RedirectResponse
    {
        [$website, $base] = $this->resolveBase($request);
        $data = $request->validate([
            'path' => ['nullable', 'string'],
            'file' => ['required', 'string'],
        ]);

        $dir = (string) $data['path'];
        $zipName = trim($dir . '/' . basename($data['file']) . '.zip', '/');
        $this->files->zip($base, $data['file'], $zipName);
        $this->record($request, 'zip', $data['file']);

        return $this->backTo($website, $dir)->with('success', 'Archive created.');
    }

    public function unzip(Request $request): RedirectResponse
    {
        [$website, $base] = $this->resolveBase($request);
        $data = $request->validate([
            'path' => ['nullable', 'string'],
            'file' => ['required', 'string'],
        ]);

        $dir = (string) $data['path'];
        $this->files->unzip($base, $data['file'], $dir);
        $this->record($request, 'unzip', $data['file']);

        return $this->backTo($website, $dir)->with('success', 'Archive extracted.');
    }

    public function destroy(Request $request): RedirectResponse
    {
        [$website, $base] = $this->resolveBase($request);
        $data = $request->validate([
            'path' => ['nullable', 'string'],
            'file' => ['required', 'string'],
        ]);

        $this->files->delete($base, $data['file']);
        $this->record($request, 'delete', $data['file']);

        return $this->backTo($website, (string) $data['path'])->with('success', 'Deleted.');
    }

    protected function createEntry(Request $request, string $kind): RedirectResponse
    {
        [$website, $base] = $this->resolveBase($request);
        $data = $request->validate([
            'path' => ['nullable', 'string'],
            'name' => ['required', 'string', 'max:255'],
        ]);

        $dir = (string) $data['path'];
        $relative = trim($dir . '/' . basename($data['name']), '/');

        $kind === 'folder'
            ? $this->files->makeDirectory($base, $relative)
            : $this->files->createFile($base, $relative);

        $this->record($request, 'create_' . $kind, $relative);

        return $this->backTo($website, $dir)->with('success', ucfirst($kind) . ' created.');
    }

    protected function resolveBase(Request $request, string $ability = 'update'): array
    {
        $website = Website::findOrFail($request->input('website', $request->query('website')));
        $this->authorize($ability, $website);
        $base = dirname($website->document_root);

        return [$website, $base];
    }

    protected function currentWebsite(Request $request, $websites): ?Website
    {
        $id = $request->query('website');

        return $id ? $websites->firstWhere('id', (int) $id) : $websites->first();
    }


    protected function backTo(Website $website, string $path): RedirectResponse
    {
        return redirect()->route('file-manager.index', ['website' => $website->id, 'path' => $path]);
    }

    protected function record(Request $request, string $operation, string $path, ?string $target = null): void
    {
        FileOperation::create([
            'user_id'     => $request->user()->id,
            'operation'   => $operation,
            'path'        => substr($path, 0, 255),
            'target_path' => $target ? substr($target, 0, 255) : null,
            'status'      => 'success',
        ]);
    }
}
