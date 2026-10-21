<?php

declare(strict_types=1);

use App\Modules\Communications\Jobs\QueueHeartbeatJob;
use App\Modules\Communications\Services\BulkEmailService;
use App\Modules\Communications\Services\DeliveryStatusService;
use App\Modules\Communications\Services\QueueHealthService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schedule;

Artisan::command('app:heartbeat', function (): void {
  $this->info('Marketplace Ministers API scheduler is running.');
})->purpose('Verify the application scheduler is operational');

Artisan::command('communications:dispatch-scheduled', function (): void {
  Cache::put(QueueHealthService::SCHEDULER_HEARTBEAT, now()->toIso8601String(), now()->addDay());
  QueueHeartbeatJob::dispatch()->onQueue((string) config('communications.campaigns.queue', 'default'));
  $count = app(BulkEmailService::class)->dispatchDue();
  $this->info("Queued {$count} scheduled campaign(s).");
})->purpose('Queue scheduled communication campaigns that are due and record queue/scheduler heartbeats');

Artisan::command('communications:reconcile', function (): void {
  $applied = app(DeliveryStatusService::class)->reconcile();
  $this->info("Applied {$applied} pending delivery event(s).");
})->purpose('Apply provider delivery callbacks that arrived before their message was recorded');

Schedule::command('app:heartbeat')->daily();
Schedule::command('communications:dispatch-scheduled')->everyMinute()->withoutOverlapping();
Schedule::command('communications:reconcile')->everyTenMinutes()->withoutOverlapping();
Schedule::command('lms:publish-scheduled')->everyFiveMinutes();
Schedule::command('membership:notify-awaiting-interview-review')->everyFifteenMinutes();
Schedule::command('events:send-reminders')->hourly();
Schedule::command('cms:sync-gallery-sources')->twiceDaily(2, 14);
