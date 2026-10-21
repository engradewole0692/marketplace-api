<?php

declare(strict_types=1);

namespace App\Modules\Communications\Jobs;

use App\Modules\Communications\Services\CampaignDispatchService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Sends one batch of campaign recipients on one channel. Recipients are claimed atomically, so a retried
 * or duplicated job never sends the same message twice. Transient provider failures are retried with backoff.
 */
final class SendCampaignRecipientsJob implements ShouldQueue
{
  use Dispatchable;
  use InteractsWithQueue;
  use Queueable;
  use SerializesModels;

  public int $tries = 4;

  public int $timeout = 900;

  /**
   * @param  list<int>  $recipientIds
   */
  public function __construct(
    public readonly int $bulkEmailJobId,
    public readonly string $channel,
    public readonly array $recipientIds,
  ) {}

  /**
   * @return list<int>
   */
  public function backoff(): array
  {
    return [60, 300, 900];
  }

  public function handle(CampaignDispatchService $campaigns): void
  {
    $needsRetry = $campaigns->sendBatch($this->bulkEmailJobId, $this->channel, $this->recipientIds, $this->attempts());
    if (! $needsRetry) {
      return;
    }

    if ($this->job instanceof \Illuminate\Queue\Jobs\SyncJob) {
      // The sync driver cannot delay; retry inline (non-production only, campaigns refuse sync in production).
      for ($attempt = 2; $needsRetry && $attempt <= $this->tries; $attempt++) {
        $needsRetry = $campaigns->sendBatch($this->bulkEmailJobId, $this->channel, $this->recipientIds, $attempt);
      }

      return;
    }

    if ($this->attempts() < $this->tries) {
      $this->release($this->backoff()[min($this->attempts() - 1, 2)]);
    }
  }
}
