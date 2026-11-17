<?php

declare(strict_types=1);

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Booking housekeeping. Needs `php artisan schedule:work` (dev) or a cron entry running
// `schedule:run` every minute (production).
Schedule::command('branchbook:expire-holds')->everyMinute()->withoutOverlapping();
Schedule::command('branchbook:send-reminders')->everyFifteenMinutes()->withoutOverlapping();
Schedule::command('branchbook:mark-no-shows')->everyFiveMinutes()->withoutOverlapping();

// Proves the scheduler is running; branchbook:health reads it.
Schedule::call(fn () => Cache::put('scheduler:heartbeat', time(), 600))->name('scheduler-heartbeat')->everyMinute();
