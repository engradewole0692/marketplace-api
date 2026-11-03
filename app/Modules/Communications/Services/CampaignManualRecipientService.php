<?php

declare(strict_types=1);

namespace App\Modules\Communications\Services;

use App\Contracts\ServiceContract;
use App\Modules\Communications\Models\BulkEmailJob;
use App\Modules\Communications\Models\CampaignManualRecipient;
use App\Modules\Communications\Support\EmailListParser;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Manually supplied email addresses for a campaign. Addresses are uploaded in bounded chunks while the
 * campaign is a draft and are merged with the module audience by RecipientAudienceService at preview
 * and send time. Entering an address here never records consent.
 */
final class CampaignManualRecipientService implements ServiceContract
{
  public static function chunkLimit(): int
  {
    return max(1, (int) config('communications.campaigns.manual_recipient_chunk', 1000));
  }

  public static function campaignLimit(): int
  {
    return max(1, (int) config('communications.campaigns.manual_recipient_limit', 50000));
  }

  /**
   * @param  list<string>  $entries
   * @return array{received: int, added: int, already_listed: int, duplicates_in_request: int, invalid: list<string>, invalid_count: int, total: int}
   */
  public function add(BulkEmailJob $job, array $entries, bool $replace = false): array
  {
    $this->assertDraft($job);
    $parsed = EmailListParser::parse($entries);

    return DB::transaction(function () use ($job, $parsed, $replace): array {
      if ($replace) {
        CampaignManualRecipient::query()->where('bulk_email_job_id', $job->id)->delete();
      }

      $existing = [];
      foreach (array_chunk($parsed['valid'], 500) as $chunk) {
        foreach (CampaignManualRecipient::query()->where('bulk_email_job_id', $job->id)->whereIn('email', $chunk)->pluck('email') as $email) {
          $existing[$email] = true;
        }
      }
      $new = array_values(array_filter($parsed['valid'], fn (string $email): bool => ! isset($existing[$email])));

      $current = $this->count($job);
      if ($current + count($new) > self::campaignLimit()) {
        throw ValidationException::withMessages([
          'emails' => 'A campaign can hold at most '.self::campaignLimit().' manually entered addresses.',
        ]);
      }

      $now = now();
      foreach (array_chunk($new, 500) as $chunk) {
        CampaignManualRecipient::query()->insertOrIgnore(array_map(fn (string $email): array => [
          'bulk_email_job_id' => $job->id,
          'email' => $email,
          'created_at' => $now,
          'updated_at' => $now,
        ], $chunk));
      }

      return [
        'received' => $parsed['total'],
        'added' => count($new),
        'already_listed' => count($existing),
        'duplicates_in_request' => count($parsed['duplicates']),
        'invalid' => array_slice($parsed['invalid'], 0, 100),
        'invalid_count' => count($parsed['invalid']),
        'total' => $this->count($job),
      ];
    });
  }

  /**
   * @param  list<string>  $emails
   */
  public function remove(BulkEmailJob $job, array $emails): int
  {
    $this->assertDraft($job);
    $normalized = array_values(array_unique(array_filter(array_map(
      fn ($email) => strtolower(trim((string) $email)),
      $emails,
    ))));

    $removed = 0;
    foreach (array_chunk($normalized, 500) as $chunk) {
      $removed += CampaignManualRecipient::query()->where('bulk_email_job_id', $job->id)->whereIn('email', $chunk)->delete();
    }

    return $removed;
  }

  public function clear(BulkEmailJob $job): int
  {
    $this->assertDraft($job);

    return CampaignManualRecipient::query()->where('bulk_email_job_id', $job->id)->delete();
  }

  public function paginate(BulkEmailJob $job, ?string $search = null, int $perPage = 100): LengthAwarePaginator
  {
    return CampaignManualRecipient::query()
      ->where('bulk_email_job_id', $job->id)
      ->when($search !== null && trim($search) !== '', fn ($q) => $q->where('email', 'like', '%'.strtolower(trim((string) $search)).'%'))
      ->orderBy('id')
      ->paginate(min(500, max(1, $perPage)));
  }

  public function count(BulkEmailJob $job): int
  {
    return $job->exists
      ? CampaignManualRecipient::query()->where('bulk_email_job_id', $job->id)->count()
      : 0;
  }

  /**
   * @return list<string>
   */
  public function emailsFor(?BulkEmailJob $job): array
  {
    if ($job === null || ! $job->exists) {
      return [];
    }

    $emails = [];
    CampaignManualRecipient::query()
      ->where('bulk_email_job_id', $job->id)
      ->select(['id', 'email'])
      ->chunkById(1000, function ($rows) use (&$emails): void {
        foreach ($rows as $row) {
          $emails[] = (string) $row->email;
        }
      });

    return $emails;
  }

  private function assertDraft(BulkEmailJob $job): void
  {
    if ($job->status !== BulkEmailJob::STATUS_DRAFT) {
      throw ValidationException::withMessages(['status' => 'Manual recipients can only be changed while the campaign is a draft.']);
    }
  }
}
