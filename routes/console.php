<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('app:heartbeat', function (): void {
  $this->info('Marketplace Ministers API scheduler is running.');
})->purpose('Verify the application scheduler is operational');

Schedule::command('app:heartbeat')->daily();
Schedule::command('lms:publish-scheduled')->everyFiveMinutes();
Schedule::command('membership:notify-awaiting-interview-review')->everyFifteenMinutes();
Schedule::command('events:send-reminders')->hourly();
