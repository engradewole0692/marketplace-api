<?php

declare(strict_types=1);

namespace App\Modules\Communications\Jobs;

use App\Modules\Communications\Models\BulkEmailJob;
use App\Modules\Communications\Models\BulkEmailRecipient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Sends a bulk email job in batches of 50.
 * Uses a simple iterator to avoid loading all recipients at once.
 */
final class SendBulkEmailBatchJob implements ShouldQueue
{
  use Dispatchable;
  use InteractsWithQueue;
  use Queueable;
  use SerializesModels;

  public int $tries = 3;

  public int $timeout = 3600; // 1 hour for very large recipient sets

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
    // Build recipient query from stored filters
    $filters = $job->recipient_filters ?? [];

    $query = \App\Models\User::query()
      ->whereNotNull('email')
      ->where('email', '!=', '')
      ->where('status', '!=', 'inactive')
      ->select('id', 'uuid', 'name', 'email');

    if (! empty($filters['audience'])) {
      match ($filters['audience']) {
        'visitors' => $query->where('type', 'visitor'),
        'members' => $query->whereHas('member', fn ($q) => $q->where('status', 'active')),
        'staff' => $query->whereHas('roles', fn ($q) => $q->where('slug', 'staff')),
        'admins' => $query->whereHas('roles', fn ($q) => $q->whereIn('slug', ['admin', 'super_admin'])),
        default => null,
      };
    }

    if (! empty($filters['country_id'])) {
      $query->whereHas('member', fn ($q) => $q->where('country_id', $filters['country_id']));
    }

    if (! empty($filters['role_slug'])) {
      $query->whereHas('roles', fn ($q) => $q->where('slug', $filters['role_slug']));
    }

    $fromName = $job->from_name ?: config('mail.from.name');
    $fromEmail = $job->from_email ?: config('mail.from.address');

    $query->chunk(50, function ($users) use ($job, $fromName, $fromEmail): void {
      if (BulkEmailJob::query()->where('id', $job->id)->value('status') === 'cancelled') {
        return; // Abort if cancelled mid-send.
      }

      $recipients = $users->map(fn ($u) => [
        'bulk_email_job_id' => $job->id,
        'email' => $u->email,
        'name' => $u->name,
        'user_id' => $u->id,
        'status' => 'pending',
        'created_at' => now(),
        'updated_at' => now(),
      ])->toArray();

      BulkEmailRecipient::query()->upsert(
        $recipients,
        ['bulk_email_job_id', 'email'],
        ['status', 'updated_at'],
      );

      foreach ($users as $user) {
        try {
          Mail::html($job->html_body, function ($message) use ($user, $job, $fromName, $fromEmail): void {
            $message->to($user->email, $user->name)
              ->subject($job->subject)
              ->from($fromEmail, $fromName);
          });

          DB::table('bulk_email_recipients')
            ->where('bulk_email_job_id', $job->id)
            ->where('email', $user->email)
            ->update(['status' => 'sent', 'sent_at' => now()]);

          DB::table('bulk_email_jobs')
            ->where('id', $job->id)
            ->increment('sent_count');

        } catch (\Throwable $e) {
          Log::warning('Bulk email send failed for recipient', [
            'job_id' => $job->id,
            'email' => $user->email,
            'error' => $e->getMessage(),
          ]);

          DB::table('bulk_email_recipients')
            ->where('bulk_email_job_id', $job->id)
            ->where('email', $user->email)
            ->update(['status' => 'failed', 'error_message' => mb_substr($e->getMessage(), 0, 500)]);

          DB::table('bulk_email_jobs')
            ->where('id', $job->id)
            ->increment('failed_count');
        }
      }
    });
  }
}
