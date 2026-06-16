<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\ProfileController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\BackupController;
use App\Http\Controllers\CronController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DatabaseController;
use App\Http\Controllers\FileManagerController;
use App\Http\Controllers\FirewallController;
use App\Http\Controllers\JobController;
use App\Http\Controllers\LogController;
use App\Http\Controllers\PhpSettingController;
use App\Http\Controllers\ReverseProxyController;
use App\Http\Controllers\ServiceController;
use App\Http\Controllers\SettingController;
use App\Http\Controllers\SetupController;
use App\Http\Controllers\SslController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\WebsiteController;
use App\Http\Controllers\WordPressController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| First-run setup wizard (only reachable while setup is incomplete)
|--------------------------------------------------------------------------
*/
Route::controller(SetupController::class)->group(function () {
    Route::get('/setup', 'index')->name('setup.index');
    Route::post('/setup', 'store')->name('setup.store');
});

/*
|--------------------------------------------------------------------------
| Guest authentication
|--------------------------------------------------------------------------
*/
Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'show'])->name('login');
    Route::post('/login', [LoginController::class, 'store'])
        ->middleware('throttle:10,1')
        ->name('login.store');
});

Route::post('/logout', [LoginController::class, 'destroy'])
    ->middleware('auth')
    ->name('logout');

/*
|--------------------------------------------------------------------------
| Authenticated panel
|--------------------------------------------------------------------------
*/
Route::middleware('auth')->group(function () {
    Route::redirect('/', '/dashboard');
    Route::get('/dashboard', DashboardController::class)->name('dashboard');

    // Profile & password (available to every authenticated user)
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::put('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::put('/password', [ProfileController::class, 'updatePassword'])->name('password.update');

    // Websites ----------------------------------------------------------------
    Route::middleware('permission:manage_websites')->group(function () {
        Route::get('/websites', [WebsiteController::class, 'index'])->name('websites.index');
        Route::get('/websites/create', [WebsiteController::class, 'create'])->name('websites.create');
        Route::post('/websites', [WebsiteController::class, 'store'])->name('websites.store');
        Route::get('/websites/{website}', [WebsiteController::class, 'show'])->name('websites.show');
        Route::get('/websites/{website}/edit', [WebsiteController::class, 'edit'])->name('websites.edit');
        Route::put('/websites/{website}', [WebsiteController::class, 'update'])->name('websites.update');
        Route::delete('/websites/{website}', [WebsiteController::class, 'destroy'])->name('websites.destroy');
        Route::post('/websites/{website}/toggle', [WebsiteController::class, 'toggle'])->name('websites.toggle');
        Route::post('/websites/{website}/suspend', [WebsiteController::class, 'suspend'])->name('websites.suspend');
        Route::post('/websites/{website}/redeploy', [WebsiteController::class, 'redeploy'])->name('websites.redeploy');
        Route::get('/websites/{website}/php', [PhpSettingController::class, 'edit'])->name('php-settings.edit');
        Route::put('/websites/{website}/php', [PhpSettingController::class, 'update'])->name('php-settings.update');
    });

    // Reverse proxy -----------------------------------------------------------
    Route::middleware('permission:manage_websites')->group(function () {
        Route::get('/reverse-proxy', [ReverseProxyController::class, 'index'])->name('reverse-proxy.index');
        Route::get('/reverse-proxy/create', [ReverseProxyController::class, 'create'])->name('reverse-proxy.create');
        Route::post('/reverse-proxy', [ReverseProxyController::class, 'store'])->name('reverse-proxy.store');
        Route::delete('/reverse-proxy/{website}', [ReverseProxyController::class, 'destroy'])->name('reverse-proxy.destroy');
    });

    // WordPress ---------------------------------------------------------------
    Route::middleware('permission:manage_websites')->group(function () {
        Route::get('/wordpress', [WordPressController::class, 'index'])->name('wordpress.index');
        Route::post('/wordpress/install', [WordPressController::class, 'install'])->name('wordpress.install');
    });

    // SSL ---------------------------------------------------------------------
    Route::middleware('permission:manage_ssl')->group(function () {
        Route::get('/ssl', [SslController::class, 'index'])->name('ssl.index');
        Route::post('/ssl/{website}/issue', [SslController::class, 'issue'])->name('ssl.issue');
        Route::post('/ssl/{website}/renew', [SslController::class, 'renew'])->name('ssl.renew');
        Route::delete('/ssl/{website}', [SslController::class, 'destroy'])->name('ssl.destroy');
    });

    // Databases ---------------------------------------------------------------
    Route::middleware('permission:manage_databases')->group(function () {
        Route::get('/databases', [DatabaseController::class, 'index'])->name('databases.index');
        Route::get('/databases/create', [DatabaseController::class, 'create'])->name('databases.create');
        Route::post('/databases', [DatabaseController::class, 'store'])->name('databases.store');
        Route::delete('/databases/{database}', [DatabaseController::class, 'destroy'])->name('databases.destroy');
        Route::post('/databases/{database}/export', [DatabaseController::class, 'export'])->name('databases.export');
        Route::post('/databases/{database}/import', [DatabaseController::class, 'import'])->name('databases.import');
        Route::delete('/database-users/{user}', [DatabaseController::class, 'destroyUser'])->name('database-users.destroy');
    });

    // File manager ------------------------------------------------------------
    Route::middleware('permission:manage_websites')->group(function () {
        Route::get('/file-manager', [FileManagerController::class, 'index'])->name('file-manager.index');
        Route::get('/file-manager/edit', [FileManagerController::class, 'edit'])->name('file-manager.edit');
        Route::put('/file-manager/edit', [FileManagerController::class, 'save'])->name('file-manager.save');
        Route::get('/file-manager/download', [FileManagerController::class, 'download'])->name('file-manager.download');
        Route::post('/file-manager/upload', [FileManagerController::class, 'upload'])->name('file-manager.upload');
        Route::post('/file-manager/folder', [FileManagerController::class, 'makeFolder'])->name('file-manager.folder');
        Route::post('/file-manager/file', [FileManagerController::class, 'makeFile'])->name('file-manager.file');
        Route::post('/file-manager/rename', [FileManagerController::class, 'rename'])->name('file-manager.rename');
        Route::post('/file-manager/chmod', [FileManagerController::class, 'chmod'])->name('file-manager.chmod');
        Route::post('/file-manager/zip', [FileManagerController::class, 'zip'])->name('file-manager.zip');
        Route::post('/file-manager/unzip', [FileManagerController::class, 'unzip'])->name('file-manager.unzip');
        Route::delete('/file-manager', [FileManagerController::class, 'destroy'])->name('file-manager.destroy');
    });

    // Backups -----------------------------------------------------------------
    Route::middleware('permission:manage_backups')->group(function () {
        Route::get('/backups', [BackupController::class, 'index'])->name('backups.index');
        Route::post('/backups', [BackupController::class, 'store'])->name('backups.store');
        Route::post('/backups/{backup}/restore', [BackupController::class, 'restore'])->name('backups.restore');
        Route::get('/backups/{backup}/download', [BackupController::class, 'download'])->name('backups.download');
        Route::delete('/backups/{backup}', [BackupController::class, 'destroy'])->name('backups.destroy');
        Route::post('/backup-schedules', [BackupController::class, 'storeSchedule'])->name('backup-schedules.store');
        Route::delete('/backup-schedules/{schedule}', [BackupController::class, 'destroySchedule'])->name('backup-schedules.destroy');
    });

    // Services ----------------------------------------------------------------
    Route::middleware('permission:manage_services')->group(function () {
        Route::get('/services', [ServiceController::class, 'index'])->name('services.index');
        Route::post('/services/{service}/action', [ServiceController::class, 'action'])->name('services.action');
        Route::get('/services/{service}/logs', [ServiceController::class, 'logs'])->name('services.logs');
    });

    // Firewall ----------------------------------------------------------------
    Route::middleware('permission:manage_firewall')->group(function () {
        Route::get('/firewall', [FirewallController::class, 'index'])->name('firewall.index');
        Route::post('/firewall/toggle', [FirewallController::class, 'toggle'])->name('firewall.toggle');
        Route::post('/firewall/rules', [FirewallController::class, 'store'])->name('firewall.store');
        Route::delete('/firewall/rules/{number}', [FirewallController::class, 'destroy'])->name('firewall.destroy');
    });

    // Cron --------------------------------------------------------------------
    Route::middleware('permission:manage_services')->group(function () {
        Route::get('/cron', [CronController::class, 'index'])->name('cron.index');
        Route::get('/cron/create', [CronController::class, 'create'])->name('cron.create');
        Route::post('/cron', [CronController::class, 'store'])->name('cron.store');
        Route::get('/cron/{cron}/edit', [CronController::class, 'edit'])->name('cron.edit');
        Route::put('/cron/{cron}', [CronController::class, 'update'])->name('cron.update');
        Route::post('/cron/{cron}/toggle', [CronController::class, 'toggle'])->name('cron.toggle');
        Route::delete('/cron/{cron}', [CronController::class, 'destroy'])->name('cron.destroy');
    });

    // Logs --------------------------------------------------------------------
    Route::middleware('permission:view_logs')->group(function () {
        Route::get('/logs', [LogController::class, 'index'])->name('logs.index');
        Route::get('/logs/download', [LogController::class, 'download'])->name('logs.download');
    });

    // Jobs (visible to any authenticated user) --------------------------------
    Route::get('/jobs', [JobController::class, 'index'])->name('jobs.index');
    Route::get('/jobs/{job}', [JobController::class, 'show'])->name('jobs.show');

    // Users -------------------------------------------------------------------
    Route::middleware('permission:manage_users')->group(function () {
        Route::get('/users', [UserController::class, 'index'])->name('users.index');
        Route::get('/users/create', [UserController::class, 'create'])->name('users.create');
        Route::post('/users', [UserController::class, 'store'])->name('users.store');
        Route::get('/users/{user}/edit', [UserController::class, 'edit'])->name('users.edit');
        Route::put('/users/{user}', [UserController::class, 'update'])->name('users.update');
        Route::delete('/users/{user}', [UserController::class, 'destroy'])->name('users.destroy');
    });

    // Settings ----------------------------------------------------------------
    Route::middleware('permission:manage_settings')->group(function () {
        Route::get('/settings', [SettingController::class, 'index'])->name('settings.index');
        Route::put('/settings', [SettingController::class, 'update'])->name('settings.update');
    });

    // Audit logs --------------------------------------------------------------
    Route::middleware('permission:view_audit_logs')->group(function () {
        Route::get('/audit-logs', [AuditLogController::class, 'index'])->name('audit-logs.index');
    });
});
