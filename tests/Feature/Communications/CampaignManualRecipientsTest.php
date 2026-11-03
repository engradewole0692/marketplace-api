<?php

declare(strict_types=1);

namespace Tests\Feature\Communications;

use App\Models\Member;
use App\Modules\Communications\Jobs\SendBulkEmailBatchJob;
use App\Modules\Communications\Jobs\SendCampaignRecipientsJob;
use App\Modules\Communications\Mail\CommunicationMailable;
use App\Modules\Communications\Models\BulkEmailJob;
use App\Modules\Communications\Models\BulkEmailRecipient;
use App\Modules\Communications\Models\CampaignManualRecipient;
use App\Modules\Communications\Models\CommunicationConsent;
use App\Modules\Communications\Services\CampaignDispatchService;
use App\Modules\Communications\Services\CommunicationConsentService;
use App\Modules\Communications\Support\DeliveryStatus;
use App\Modules\Communications\Support\EmailListParser;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\Feature\Iam\IamTestCase;

final class CampaignManualRecipientsTest extends IamTestCase
{
  private const BASE = '/api/v1/communications/bulk-email';

  protected function setUp(): void
  {
    parent::setUp();
    Http::preventStrayRequests();
    Sanctum::actingAs($this->admin);
  }

  // ── Parsing ────────────────────────────────────────────────────────────

  public function test_parser_accepts_comma_lists_with_and_without_spaces(): void
  {
    $this->assertSame(
      ['person1@example.com', 'person2@example.com', 'person3@example.com'],
      EmailListParser::parse('person1@example.com,person2@example.com,person3@example.com')['valid'],
    );
    $this->assertSame(
      ['person1@example.com', 'person2@example.com', 'person3@example.com'],
      EmailListParser::parse('person1@example.com, person2@example.com,   person3@example.com')['valid'],
    );
  }

  public function test_parser_accepts_newline_semicolon_tab_and_mixed_separators(): void
  {
    $this->assertSame(['a@example.com', 'b@example.com', 'c@example.com'], EmailListParser::parse("a@example.com\nb@example.com\r\nc@example.com")['valid']);
    $this->assertSame(['a@example.com', 'b@example.com', 'c@example.com'], EmailListParser::parse('a@example.com;b@example.com; c@example.com')['valid']);

    $mixed = EmailListParser::parse(" a@example.com ,b@example.com;\n\tc@example.com\t d@example.com e@example.com,, ;\n\nF@Example.COM ");
    $this->assertSame(['a@example.com', 'b@example.com', 'c@example.com', 'd@example.com', 'e@example.com', 'f@example.com'], $mixed['valid']);
    $this->assertSame([], $mixed['invalid']);
  }

  public function test_parser_reports_invalid_and_duplicates_without_damaging_named_addresses(): void
  {
    $result = EmailListParser::parse("Ada Obi <ada@example.com>, mailto:bola@example.com, not-an-email, ada@EXAMPLE.com, broken @example.com, missing@domain");

    $this->assertSame(['ada@example.com', 'bola@example.com'], $result['valid']);
    $this->assertSame(['ada@example.com'], $result['duplicates']);
    $this->assertSame(['not-an-email', 'broken @example.com', 'missing@domain'], $result['invalid']);
    $this->assertSame(6, $result['total']);
  }

  // ── Draft persistence ──────────────────────────────────────────────────

  public function test_manual_recipients_persist_on_a_draft_and_are_visible_when_reopened(): void
  {
    $job = $this->draft();

    $res = $this->postJson(self::BASE."/{$job->uuid}/manual-recipients", [
      'emails' => ['one@example.com', 'Two@Example.com', 'one@example.com', 'bad-address', ' three@example.com '],
    ])->assertOk();
    $res->assertJsonPath('data.added', 3)
      ->assertJsonPath('data.duplicates_in_request', 1)
      ->assertJsonPath('data.invalid_count', 1)
      ->assertJsonPath('data.invalid.0', 'bad-address')
      ->assertJsonPath('data.total', 3);

    $this->postJson(self::BASE."/{$job->uuid}/manual-recipients", ['emails' => ['two@example.com', 'four@example.com']])
      ->assertOk()
      ->assertJsonPath('data.added', 1)
      ->assertJsonPath('data.already_listed', 1)
      ->assertJsonPath('data.total', 4);

    $this->getJson(self::BASE."/{$job->uuid}")->assertOk()->assertJsonPath('data.job.manual_recipient_count', 4);
    $list = $this->getJson(self::BASE."/{$job->uuid}/manual-recipients")->assertOk();
    $this->assertSame(['one@example.com', 'two@example.com', 'three@example.com', 'four@example.com'], array_column($list->json('data.data'), 'email'));
    $list->assertJsonPath('data.editable', true);

    $this->deleteJson(self::BASE."/{$job->uuid}/manual-recipients", ['emails' => ['THREE@example.com']])
      ->assertOk()->assertJsonPath('data.removed', 1)->assertJsonPath('data.total', 3);
    $this->deleteJson(self::BASE."/{$job->uuid}/manual-recipients", ['all' => true])
      ->assertOk()->assertJsonPath('data.total', 0);
  }

  public function test_requests_are_chunked_and_non_drafts_are_locked(): void
  {
    config(['communications.campaigns.manual_recipient_chunk' => 5]);
    $job = $this->draft();

    $this->postJson(self::BASE."/{$job->uuid}/manual-recipients", [
      'emails' => array_map(fn ($i) => "c{$i}@example.com", range(1, 6)),
    ])->assertStatus(422)->assertJsonValidationErrors('emails');

    $this->postJson(self::BASE."/{$job->uuid}/manual-recipients", ['emails' => ['ok@example.com']])->assertOk();
    $job->forceFill(['status' => BulkEmailJob::STATUS_COMPLETED])->save();
    $this->postJson(self::BASE."/{$job->uuid}/manual-recipients", ['emails' => ['late@example.com']])
      ->assertStatus(422)->assertJsonValidationErrors('status');
  }

  public function test_manual_recipient_endpoints_require_campaign_permission(): void
  {
    $job = $this->draft();
    Sanctum::actingAs($this->memberUser());

    $this->getJson(self::BASE."/{$job->uuid}/manual-recipients")->assertForbidden();
    $this->postJson(self::BASE."/{$job->uuid}/manual-recipients", ['emails' => ['x@example.com']])->assertForbidden();
    $this->assertSame(0, CampaignManualRecipient::query()->count());
  }

  // ── Audience merge, consent and delivery ───────────────────────────────

  public function test_preview_reports_overlap_with_database_audience(): void
  {
    $this->member(['application_number' => 'T-1', 'email' => 'shared@example.com']);
    $this->member(['application_number' => 'T-2', 'email' => 'db-only@example.com']);
    $job = $this->draft(['recipient_filters' => ['module' => 'tribe']]);
    $this->postJson(self::BASE."/{$job->uuid}/manual-recipients", ['emails' => ['SHARED@example.com', 'manual@example.com']])->assertOk();

    $preview = $this->postJson(self::BASE.'/estimate', [
      'recipient_filters' => ['module' => 'tribe'],
      'channels' => ['email'],
      'campaign_id' => $job->uuid,
    ])->assertOk();

    $preview->assertJsonPath('data.manual.total', 2)
      ->assertJsonPath('data.manual.already_in_audience', 1)
      ->assertJsonPath('data.manual.added', 1)
      ->assertJsonPath('data.total_messages', 3);
  }

  public function test_combined_campaign_sends_once_per_address_and_respects_opt_out_and_suppression(): void
  {
    Mail::fake();
    Queue::fake();
    $this->member(['application_number' => 'T-1', 'email' => 'shared@example.com']);
    $this->member(['application_number' => 'T-2', 'email' => 'db-only@example.com']);
    app(CommunicationConsentService::class)->record('email', 'optedout@example.com', CommunicationConsent::OPTED_OUT, 'promotional', 'test');
    app(CommunicationConsentService::class)->record('email', 'suppressed@example.com', CommunicationConsent::SUPPRESSED, 'all', 'test', 'hard bounce');

    $job = $this->draft(['recipient_filters' => ['module' => 'tribe']]);
    $this->postJson(self::BASE."/{$job->uuid}/manual-recipients", [
      'emails' => ['shared@example.com', 'manual@example.com', 'optedout@example.com', 'suppressed@example.com'],
    ])->assertOk();

    $this->postJson(self::BASE."/{$job->uuid}/send", ['action' => 'send'])->assertOk()->assertJsonPath('data.job.status', 'queued');
    Queue::assertPushed(SendBulkEmailBatchJob::class, 1);

    $campaigns = app(CampaignDispatchService::class);
    $campaigns->start($job->id);
    $campaigns->start($job->id);
    $ids = BulkEmailRecipient::query()->where('bulk_email_job_id', $job->id)->where('status', DeliveryStatus::PENDING)->pluck('id')->all();
    $campaigns->sendBatch($job->id, 'email', $ids);
    $campaigns->sendBatch($job->id, 'email', $ids);

    Mail::assertSent(CommunicationMailable::class, 3);
    foreach (['shared@example.com', 'db-only@example.com', 'manual@example.com'] as $address) {
      Mail::assertSent(CommunicationMailable::class, fn ($mail) => $mail->hasTo($address));
    }
    Mail::assertNotSent(CommunicationMailable::class, fn ($mail) => $mail->hasTo('optedout@example.com') || $mail->hasTo('suppressed@example.com'));

    $rows = BulkEmailRecipient::query()->where('bulk_email_job_id', $job->id)->get()->keyBy('destination');
    $this->assertSame(1, BulkEmailRecipient::query()->where('bulk_email_job_id', $job->id)->where('destination', 'shared@example.com')->count());
    $this->assertSame('both', $rows['shared@example.com']->source);
    $this->assertSame('audience', $rows['db-only@example.com']->source);
    $this->assertSame('manual', $rows['manual@example.com']->source);
    $this->assertSame(DeliveryStatus::SKIPPED, $rows['optedout@example.com']->status);
    $this->assertSame('opted_out', $rows['optedout@example.com']->skip_reason);
    $this->assertSame('suppressed', $rows['suppressed@example.com']->skip_reason);
    $this->assertSame('manual', $rows['suppressed@example.com']->source);

    $detail = $this->getJson(self::BASE."/{$job->uuid}")->assertOk();
    $this->assertSame(2, $detail->json('data.job.source_breakdown.manual.skipped'));
    $this->assertSame(1, $detail->json('data.job.source_breakdown.both.accepted'));
    $this->getJson(self::BASE."/{$job->uuid}/recipients?source=manual")->assertOk()->assertJsonCount(3, 'data.data');

    $csv = $this->get(self::BASE."/{$job->uuid}/export")->assertOk()->streamedContent();
    $this->assertStringContainsString('source', strtok($csv, "\n"));
    $this->assertStringContainsString(',manual,', $csv);
  }

  public function test_manual_only_campaign_without_database_audience(): void
  {
    Queue::fake();
    $job = $this->draft(['recipient_filters' => ['module' => 'none']]);

    $this->postJson(self::BASE."/{$job->uuid}/send", ['action' => 'send'])->assertStatus(422)->assertJsonValidationErrors('recipient_filters');

    $this->postJson(self::BASE."/{$job->uuid}/manual-recipients", ['emails' => ['solo@example.com']])->assertOk();
    $this->postJson(self::BASE."/{$job->uuid}/send", ['action' => 'send'])->assertOk();

    app(CampaignDispatchService::class)->start($job->id);
    $this->assertSame(['solo@example.com'], BulkEmailRecipient::query()->where('bulk_email_job_id', $job->id)->pluck('destination')->all());
    $this->assertSame(0, Member::query()->count());
  }

  public function test_large_manual_list_is_queued_in_batches(): void
  {
    Queue::fake();
    config(['communications.campaigns.batch_size' => 50, 'communications.campaigns.confirmation_threshold' => 100000]);
    $job = $this->draft(['recipient_filters' => ['module' => 'none']]);

    foreach (array_chunk(array_map(fn ($i) => "bulk{$i}@example.com", range(1, 2400)), 1000) as $chunk) {
      $this->postJson(self::BASE."/{$job->uuid}/manual-recipients", ['emails' => $chunk])->assertOk();
    }
    $this->assertSame(2400, CampaignManualRecipient::query()->where('bulk_email_job_id', $job->id)->count());

    $this->postJson(self::BASE."/{$job->uuid}/send", ['action' => 'send'])->assertOk();
    app(CampaignDispatchService::class)->start($job->id);

    $this->assertSame(2400, BulkEmailRecipient::query()->where('bulk_email_job_id', $job->id)->where('status', DeliveryStatus::PENDING)->count());
    Queue::assertPushed(SendCampaignRecipientsJob::class, 48);
    $this->assertSame(2400, $job->fresh()->estimated_count);
  }

  public function test_failed_recipients_are_reported_individually(): void
  {
    Queue::fake();
    config(['communications.campaigns.max_attempts' => 1]);
    Mail::shouldReceive('to')->andReturnUsing(function (string $to) {
      if ($to === 'bounce@example.com') {
        throw new TransportException('550 mailbox unavailable');
      }

      return new class
      {
        public function send(mixed $mailable): mixed
        {
          return null;
        }
      };
    });

    $job = $this->draft(['recipient_filters' => ['module' => 'none']]);
    $this->postJson(self::BASE."/{$job->uuid}/manual-recipients", ['emails' => ['ok@example.com', 'bounce@example.com']])->assertOk();
    $this->postJson(self::BASE."/{$job->uuid}/send", ['action' => 'send'])->assertOk();

    $campaigns = app(CampaignDispatchService::class);
    $campaigns->start($job->id);
    $campaigns->sendBatch($job->id, 'email', BulkEmailRecipient::query()->where('bulk_email_job_id', $job->id)->pluck('id')->all());

    $rows = BulkEmailRecipient::query()->where('bulk_email_job_id', $job->id)->get()->keyBy('destination');
    $this->assertSame(DeliveryStatus::ACCEPTED, $rows['ok@example.com']->status);
    $this->assertSame(DeliveryStatus::FAILED, $rows['bounce@example.com']->status);
    $this->assertSame('smtp_error', $rows['bounce@example.com']->error_code);
    $this->assertStringContainsString('550', (string) $rows['bounce@example.com']->error_message);

    $job->refresh();
    $this->assertSame(BulkEmailJob::STATUS_PARTIAL, $job->status);
    $this->assertSame(1, (int) $job->failed_count);
    $this->getJson(self::BASE."/{$job->uuid}/recipients?status=failed")->assertOk()->assertJsonCount(1, 'data.data');
  }

  /**
   * @param  array<string, mixed>  $overrides
   */
  private function draft(array $overrides = []): BulkEmailJob
  {
    $res = $this->postJson(self::BASE, array_merge([
      'subject' => 'Manual list campaign',
      'html_body' => '<p>Hello {{first_name}}</p>',
      'channels' => ['email'],
      'message_type' => 'promotional',
      'recipient_filters' => ['module' => 'none'],
      'action' => 'draft',
    ], $overrides))->assertCreated();

    return BulkEmailJob::query()->where('uuid', $res->json('data.job.id'))->firstOrFail();
  }

  /**
   * @param  array<string, mixed>  $attributes
   */
  private function member(array $attributes): Member
  {
    return Member::factory()->create(array_merge(['email' => fake()->unique()->safeEmail(), 'phone' => null], $attributes));
  }
}
