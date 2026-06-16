<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| LibreStack Panel scheduled tasks
|--------------------------------------------------------------------------
| Driven by the Laravel scheduler. On a server, a single system cron entry
| (`* * * * * php artisan schedule:run`) triggers all of these.
*/
Schedule::command('librestack:run-backups --frequency=daily')->dailyAt('03:00');
Schedule::command('librestack:run-backups --frequency=weekly')->weeklyOn(0, '03:30');
Schedule::command('librestack:run-backups --frequency=monthly')->monthlyOn(1, '04:00');
Schedule::command('librestack:renew-ssl --days=30')->dailyAt('02:30');
