<?php

namespace App\Http\Controllers;

use App\Models\DatabaseUser;
use App\Models\PanelDatabase;
use App\Models\Website;
use App\Jobs\ImportDatabaseJob;
use App\Services\Database\DatabaseService;
use App\Support\Audit;
use App\Support\Validators;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class DatabaseController extends Controller
{
    public function __construct(protected DatabaseService $databases)
    {
    }

    public function index(Request $request)
    {
        $search = (string) $request->query('q', '');
        $user = $request->user();

        $items = PanelDatabase::with('users', 'website')
            ->when(! $user->isAdmin(), function ($q) use ($user) {
                $q->where('user_id', $user->id)
                    ->orWhereHas('website', fn ($w) => $w->where('user_id', $user->id));
            })
            ->when($search !== '', fn ($q) => $q->where('name', 'like', "%{$search}%"))
            ->latest()
            ->paginate(15)
            ->withQueryString();

        return view('databases.index', compact('items', 'search'));
    }

    public function create()
    {
        return view('databases.create', [
            'websites' => Website::orderBy('domain')->get(),
            'suggestedPassword' => $this->databases->generatePassword(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', PanelDatabase::class);

        $data = $request->validate([
            'name'        => ['required', 'string', 'max:63', 'unique:panel_databases,name'],
            'username'    => ['required', 'string', 'max:32'],
            'password'    => ['required', 'string', 'min:8', 'max:255'],
            'website_id'  => ['nullable', 'exists:websites,id'],
        ]);

        if (! Validators::isValidDatabaseName($data['name'])) {
            return back()->withInput()->withErrors(['name' => 'Database name may only contain letters, numbers and underscores.']);
        }
        if (! Validators::isValidDatabaseUser($data['username'])) {
            return back()->withInput()->withErrors(['username' => 'Invalid database username.']);
        }

        $this->databases->createDatabase($data['name']);
        $this->databases->createUser($data['username'], $data['password']);
        $this->databases->grant($data['username'], $data['name']);

        $database = PanelDatabase::create([
            'name'       => $data['name'],
            'website_id' => $data['website_id'] ?? null,
            'user_id'    => $request->user()->id,
            'size_bytes' => $this->databases->size($data['name']),
        ]);

        DatabaseUser::create([
            'username'          => $data['username'],
            'host'              => 'localhost',
            'panel_database_id' => $database->id,
        ]);

        Audit::log('database.created', 'database', (string) $database->id, [
            'name' => $data['name'], 'user' => $data['username'],
        ]);

        // Show the password exactly once via a flash message.
        return redirect()->route('databases.index')
            ->with('success', "Database '{$data['name']}' created.")
            ->with('db_credentials', [
                'name'     => $data['name'],
                'username' => $data['username'],
                'password' => $data['password'],
            ]);
    }

    public function destroy(PanelDatabase $database): RedirectResponse
    {
        $this->authorize('delete', $database);

        foreach ($database->users as $user) {
            $this->databases->dropUser($user->username, $user->host);
        }
        $this->databases->dropDatabase($database->name);

        $name = $database->name;
        $database->users()->delete();
        $database->delete();

        Audit::log('database.deleted', 'database', (string) $database->id, ['name' => $name]);

        return back()->with('success', "Database '{$name}' deleted.");
    }

    public function destroyUser(DatabaseUser $user): RedirectResponse
    {
        if ($user->panel_database_id) {
            $this->authorize('update', PanelDatabase::findOrFail($user->panel_database_id));
        }

        $this->databases->dropUser($user->username, $user->host);
        $username = $user->username;
        $user->delete();

        Audit::log('database_user.deleted', 'database_user', (string) $user->id, ['user' => $username]);

        return back()->with('success', "Database user '{$username}' deleted.");
    }

    public function export(PanelDatabase $database): BinaryFileResponse|RedirectResponse
    {
        $this->authorize('view', $database);

        $dir = storage_path('app/db-exports');
        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $file = $dir . '/' . $database->name . '_' . now()->format('Ymd_His') . '.sql';

        $result = $this->databases->export($database->name, $file);

        Audit::log('database.exported', 'database', (string) $database->id, ['name' => $database->name]);

        if ($result->disabled) {
            return back()->with('error', 'Export requires system mode (LIBRESTACK_SYSTEM_ENABLED=true).');
        }
        if (! is_file($file)) {
            return back()->with('error', 'Export failed: ' . $result->combined());
        }

        return response()->download($file)->deleteFileAfterSend(true);
    }

    public function import(Request $request, PanelDatabase $database): RedirectResponse
    {
        $this->authorize('update', $database);

        $request->validate([
            'sql_file' => ['required', 'file', 'mimetypes:text/plain,application/sql,application/octet-stream', 'max:51200'],
        ]);

        // Persist the upload so the queued job can read it after the request ends.
        $stored = $request->file('sql_file')->store('db-imports');
        $path = storage_path('app/' . $stored);

        ImportDatabaseJob::dispatch($database->id, $path);

        Audit::log('database.imported', 'database', (string) $database->id, ['name' => $database->name]);

        return back()->with('success', "Database import queued for '{$database->name}'.");
    }
}
