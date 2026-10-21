<?php

declare(strict_types=1);

namespace App\Modules\Communications\Services;

use App\Contracts\ServiceContract;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Makes a stopped queue worker or scheduler visible. The scheduler dispatches a heartbeat job every
 * minute; the worker records when it processed it.
 */
final class QueueHealthService implements ServiceContract
{
    public const WORKER_HEARTBEAT = 'communications:queue-worker-heartbeat';

    public const SCHEDULER_HEARTBEAT = 'communications:scheduler-heartbeat';

    /**
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        $connection = (string) config('queue.default');
        $driver = (string) config("queue.connections.{$connection}.driver", $connection);
        $pending = null;
        $oldestAgeSeconds = null;
        $failed = null;

        if ($driver === 'database') {
            $table = (string) config("queue.connections.{$connection}.table", 'jobs');
            if (Schema::hasTable($table)) {
                $pending = DB::table($table)->count();
                $oldest = DB::table($table)->min('available_at');
                $oldestAgeSeconds = $oldest ? max(0, now()->timestamp - (int) $oldest) : null;
            }
        }
        if (Schema::hasTable('failed_jobs')) {
            $failed = DB::table('failed_jobs')->count();
        }

        $worker = Cache::get(self::WORKER_HEARTBEAT);
        $scheduler = Cache::get(self::SCHEDULER_HEARTBEAT);
        $workerAt = $worker ? Carbon::parse($worker) : null;
        $schedulerAt = $scheduler ? Carbon::parse($scheduler) : null;

        $status = match (true) {
            $driver === 'sync' => 'sync',
            $workerAt !== null && $workerAt->gt(now()->subMinutes(5)) => 'running',
            $pending !== null && $pending > 0 && $oldestAgeSeconds !== null && $oldestAgeSeconds > 300 => 'stalled',
            $workerAt === null => 'unknown',
            default => 'stalled',
        };

        return [
            'connection' => $connection,
            'driver' => $driver,
            'worker_status' => $status,
            'worker_last_seen_at' => $workerAt?->toIso8601String(),
            'scheduler_last_seen_at' => $schedulerAt?->toIso8601String(),
            'scheduler_running' => $schedulerAt !== null && $schedulerAt->gt(now()->subMinutes(5)),
            'pending_jobs' => $pending,
            'oldest_pending_age_seconds' => $oldestAgeSeconds,
            'failed_jobs' => $failed,
            'sync_allowed' => ! app()->environment('production'),
            'notes' => match ($status) {
                'sync' => 'QUEUE_CONNECTION=sync runs jobs inline. Campaigns are refused in production with this setting.',
                'stalled' => 'Jobs are waiting but no worker has processed the heartbeat recently. Start or restart "php artisan queue:work".',
                'unknown' => 'No worker heartbeat recorded yet. Ensure "php artisan schedule:run" runs every minute and a queue worker is running.',
                default => 'Queue worker is processing jobs.',
            },
        ];
    }

    public function canQueueCampaigns(): bool
    {
        return ! ((string) config('queue.connections.'.config('queue.default').'.driver', config('queue.default')) === 'sync'
            && app()->environment('production'));
    }
}
