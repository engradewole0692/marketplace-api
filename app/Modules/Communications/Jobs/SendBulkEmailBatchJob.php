<?php

declare(strict_types=1);

namespace App\Modules\Communications\Jobs;

use App\Modules\Communications\Models\BulkEmailJob;
use App\Modules\Communications\Models\BulkEmailRecipient;
use App\Modules\Communications\Services\BulkEmailService;
use App\Modules\Communications\Services\OutboundMessageService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Sends a bulk communication job in batches of 50.
 */
final class SendBulkEmailBatchJob implements ShouldQueue
{
  use Dispatchable;
  use InteractsWithQueue;
  use Queueable;
  use SerializesModels;

  public int $tries = 3;

  public int $timeout = 3600;

  public function __construct(
    private readonly int $bulkEmailJobId,
  ) {}

  public function handle(): void
  {
    $job = BulkEmailJob::query()->find($this->bulkEmailJobId);

    if ($job === null || $job->status === 'cancelled') {
      return;
    }

    $job->status = 'sending';
    $job->started_at = now();
    $job->save();

    try {
      $this->processJob($job);
    } catch (\Throwable $e) {
      $job->status = 'failed';
      $job->save();

      Log::error('BulkEmailBatchJob failed', [
        'job_id' => $job->id,
        'error' => $e->getMessage(),
      ]);

      throw $e;
    }

    $job->status = 'completed';
    $job->completed_at = now();
    $job->save();
  }

  private function processJob(BulkEmailJob $job): void
  {
    $filters = $job->recipient_filters ?? [];
    $channel = strtolower((string) ($filters['channel'] ?? 'email'));
    $recipients = app(BulkEmailService::class)->collectRecipients($filters);
    $fromName = $job->from_name ?: config('mail.from.name');
    $fromEmail = $job->from_email ?: config('mail.from.address');
    $outbound = app(OutboundMessageService::class);

    foreach ($recipients->chunk(50) as $batch) {
      if (BulkEmailJob::query()->where('id', $job->id)->value('status') === 'cancelled') {
        return;
      }

      $records = $batch->map(fn (array $row) => [
        'bulk_email_job_id' => $job->id,
        'email' => $row['email'] ?: ($row['phone'] ?? ''),
        'name' => $row['name'],
        'user_id' => $row['user_id'],
        'status' => 'pending',
        'created_at' => now(),
        'updated_at' => now(),
      ])->all();

      BulkEmailRecipient::query()->upsert(
        $records,
        ['bulk_email_job_id', 'email'],
        ['status', 'updated_at'],
      );

      foreach ($batch as $recipient) {
        $key = $recipient['email'] ?: ($recipient['phone'] ?? '');
        try {
          if ($channel === 'sms' || $channel === 'whatsapp') {
            $message = $job->text_body ?: strip_tags((string) $job->html_body);
            $result = $outbound->send($channel, (string) $recipient['phone'], $message, [
              'subject' => $job->subject,
            ]);
            if ($result->status !== 'sent') {
              throw new \RuntimeException((string) ($result->error_message ?: strtoupper($channel).' was not sent.'));
            }
          } else {
            if (! filled($recipient['email'])) {
              throw new \RuntimeException('Recipient has no email address.');
            }
            Mail::html($job->html_body, function ($message) use ($recipient, $job, $fromName, $fromEmail): void {
              $message->to($recipient['email'], $recipient['name'])
                ->subject($job->subject)
                ->from($fromEmail, $fromName);
            });
          }

          DB::table('bulk_email_recipients')
            ->where('bulk_email_job_id', $job->id)
            ->where('email', $key)
            ->update(['status' => 'sent', 'sent_at' => now()]);

          DB::table('bulk_email_jobs')
            ->where('id', $job->id)
            ->increment('sent_count');
        } catch (\Throwable $e) {
          Log::warning('Bulk communication send failed for recipient', [
            'job_id' => $job->id,
            'channel' => $channel,
            'error' => $e->getMessage(),
          ]);

          DB::table('bulk_email_recipients')
            ->where('bulk_email_job_id', $job->id)
            ->where('email', $key)
            ->update(['status' => 'failed', 'error_message' => mb_substr($e->getMessage(), 0, 500)]);

          DB::table('bulk_email_jobs')
            ->where('id', $job->id)
            ->increment('failed_count');
        }
      }
    }
  }
}
