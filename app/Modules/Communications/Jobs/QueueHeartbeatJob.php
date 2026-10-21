<?php

declare(strict_types=1);

namespace App\Modules\Communications\Jobs;

use App\Modules\Communications\Services\QueueHealthService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;

/**
 * Dispatched by the scheduler every minute; when a worker processes it the health check knows the queue is alive.
 */
final class QueueHeartbeatJob implements ShouldQueue
{
  use Dispatchable;
  use InteractsWithQueue;
  use Queueable;

  public int $tries = 1;

  public function handle(): void
  {
    Cache::put(QueueHealthService::WORKER_HEARTBEAT, now()->toIso8601String(), now()->addDay());
  }
}
