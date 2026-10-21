<?php

declare(strict_types=1);

namespace App\Modules\Communications\Jobs;

use App\Modules\Communications\Models\BulkEmailJob;
use App\Modules\Communications\Services\CampaignDispatchService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Starts a campaign: re-resolves the audience at send time, records recipient rows and enqueues
 * rate-limited SendCampaignRecipientsJob batches. Safe to run twice; only a queued campaign is claimed.
 */
final class SendBulkEmailBatchJob implements ShouldQueue
{
  use Dispatchable;
  use InteractsWithQueue;
  use Queueable;
  use SerializesModels;

  public int $tries = 3;

  public int $timeout = 600;

  /** @var list<int> */
  public array $backoff = [30, 120, 600];

  public function __construct(
    private readonly int $bulkEmailJobId,
  ) {}

  public function handle(CampaignDispatchService $campaigns): void
  {
    $campaigns->start($this->bulkEmailJobId);
  }

  public function failed(\Throwable $exception): void
  {
    Log::error('communications.campaign.start_failed', ['job_id' => $this->bulkEmailJobId, 'error' => class_basename($exception)]);
    BulkEmailJob::query()
      ->whereKey($this->bulkEmailJobId)
      ->whereIn('status', [BulkEmailJob::STATUS_QUEUED, BulkEmailJob::STATUS_PROCESSING])
      ->update(['status' => BulkEmailJob::STATUS_FAILED, 'last_error' => 'Campaign could not be started: '.mb_substr($exception->getMessage(), 0, 400)]);
  }
}
