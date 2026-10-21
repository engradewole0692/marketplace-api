<?php

declare(strict_types=1);

namespace Tests\Feature\Communications;

use App\Models\Member;
use App\Models\Permission;
use App\Models\Person;
use App\Models\Role;
use App\Models\User;
use App\Modules\Cms\Contracts\SmsNotifierContract;
use App\Modules\Cms\Contracts\WhatsAppNotifierContract;
use App\Modules\Communications\Jobs\SendBulkEmailBatchJob;
use App\Modules\Communications\Mail\CommunicationMailable;
use App\Modules\Communications\Models\BulkEmailJob;
use App\Modules\Communications\Models\BulkEmailRecipient;
use App\Modules\Communications\Models\CommunicationConsent;
use App\Modules\Communications\Models\CommunicationDeliveryEvent;
use App\Modules\Communications\Models\CommunicationOutboundMessage;
use App\Modules\Communications\Models\CommunicationWhatsAppTemplate;
use App\Modules\Communications\Services\BulkEmailService;
use App\Modules\Communications\Services\CampaignDispatchService;
use App\Modules\Communications\Services\CampaignMessageRenderer;
use App\Modules\Communications\Services\CommunicationConsentService;
use App\Modules\Communications\Services\DeliveryStatusService;
use App\Modules\Communications\Services\OutboundMessageService;
use App\Modules\Communications\Services\ProviderConfigurationService;
use App\Modules\Communications\Services\QueueHealthService;
use App\Modules\Communications\Services\RecipientAudienceService;
use App\Modules\Communications\Support\DeliveryStatus;
use App\Modules\Communications\Support\PhoneNumberService;
use App\Modules\Communications\Support\SmsSegmentCalculator;
use App\Modules\Events\Enums\EventStatus;
use App\Modules\Events\Enums\EventVisibility;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Models\EventRegistration;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Iam\IamTestCase;

final class MultichannelCampaignTest extends IamTestCase
{
    private const TERMII_SECRET = 'termii-test-secret';

    private const META_SECRET = 'meta-app-secret';

    private const TWILIO_TOKEN = 'twilio-auth-token';

    private int $termiiCounter = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ── Phone numbers and segments ─────────────────────────────────────────

    public function test_phone_numbers_normalize_to_e164_with_region_awareness(): void
    {
        $phones = app(PhoneNumberService::class);

        $this->assertSame('+2348031234567', $phones->normalize('08031234567')['e164']);
        $this->assertSame('+2348031234567', $phones->normalize('+234 803 123 4567')['e164']);
        $this->assertSame('+2348031234567', $phones->normalize('002348031234567')['e164']);
        $this->assertSame('+233241234567', $phones->normalize('0241234567', 'GH')['e164']);
        $this->assertSame('+447911123456', $phones->normalize('+44 7911 123456')['e164']);
        $this->assertSame('+14155552671', $phones->normalize('+1 415 555 2671')['e164']);

        $invalid = $phones->normalize('12345');
        $this->assertFalse($invalid['valid']);
        $this->assertSame('invalid_phone', $invalid['reason']);
        $this->assertSame('missing_phone', $phones->normalize(null)['reason']);
        $this->assertSame('missing_phone', $phones->normalize('  ')['reason']);

        $masked = PhoneNumberService::mask('+2348031234567');
        $this->assertStringNotContainsString('8031234', (string) $masked);
        $this->assertStringEndsWith('567', (string) $masked);
    }

    public function test_sms_segments_account_for_gsm_and_unicode(): void
    {
        $calc = app(SmsSegmentCalculator::class);

        $this->assertSame(['GSM-7', 1], [$calc->calculate(str_repeat('a', 160))['encoding'], $calc->calculate(str_repeat('a', 160))['segments']]);
        $this->assertSame(2, $calc->calculate(str_repeat('a', 161))['segments']);
        $this->assertSame(3, $calc->calculate(str_repeat('a', 307))['segments']);
        $this->assertSame('UCS-2', $calc->calculate('Hello 🙏')['encoding']);
        $this->assertSame(1, $calc->calculate(str_repeat('ж', 70))['segments']);
        $this->assertSame(2, $calc->calculate(str_repeat('ж', 71))['segments']);
    }

    // ── Recipient resolution ───────────────────────────────────────────────

    public function test_event_audience_returns_only_that_events_registrants(): void
    {
        $event = $this->event('Target Event');
        $other = $this->event('Other Event');
        $this->registration($event, 'reg.one@example.com', '08031234567');
        $this->registration($event, 'reg.two@example.com', '08031234568');
        $this->registration($other, 'other.event@example.com', '08031234569');

        $resolved = app(RecipientAudienceService::class)->resolve(['module' => 'events', 'event_id' => $event->uuid], ['email', 'sms']);

        $emails = array_column($resolved['deliveries']['email'], 'destination');
        sort($emails);
        $this->assertSame(['reg.one@example.com', 'reg.two@example.com'], $emails);
        $this->assertSame(2, $resolved['channels']['sms']['eligible']);
        $this->assertSame(4, $resolved['summary']['total_messages']);
    }

    public function test_tribe_audience_returns_only_tribe_applicants(): void
    {
        $this->member(['application_number' => 'TRIBE-1', 'email' => 'tribe.one@example.com', 'phone' => '08031230001']);
        $this->member(['application_number' => null, 'email' => 'not.tribe@example.com', 'phone' => '08031230002']);

        $resolved = app(RecipientAudienceService::class)->resolve(['module' => 'tribe'], ['email']);

        $this->assertSame(['tribe.one@example.com'], array_column($resolved['deliveries']['email'], 'destination'));
    }

    public function test_visitor_audience_excludes_approved_members(): void
    {
        $visitor = User::factory()->create(['email' => 'visitor.only@example.com']);
        $memberUser = User::factory()->create(['email' => 'approved.member@example.com']);
        Member::factory()->active()->create(['user_id' => $memberUser->id, 'email' => 'approved.member@example.com']);

        $resolved = app(RecipientAudienceService::class)->resolve(['module' => 'visitors'], ['email']);
        $emails = array_column($resolved['deliveries']['email'], 'destination');

        $this->assertContains($visitor->email, $emails);
        $this->assertNotContains('approved.member@example.com', $emails);
    }

    public function test_preview_reports_invalid_missing_duplicates_and_opt_outs_per_channel(): void
    {
        $this->member(['application_number' => 'T-A', 'email' => 'a@example.com', 'phone' => '08031234567']);
        $this->member(['application_number' => 'T-B', 'email' => 'b@example.com', 'phone' => '12345']);
        $this->member(['application_number' => 'T-C', 'email' => 'c@example.com', 'phone' => '+2348031234567']);
        $this->member(['application_number' => 'T-D', 'email' => 'd@example.com', 'phone' => '08039999999']);
        $this->member(['application_number' => 'T-E', 'email' => 'not-an-email', 'phone' => '08037777777']);
        app(CommunicationConsentService::class)->record('sms', '+2348039999999', CommunicationConsent::OPTED_OUT, 'promotional', 'test');
        app(CommunicationConsentService::class)->record('email', 'c@example.com', CommunicationConsent::SUPPRESSED, 'all', 'bounce_test');

        Sanctum::actingAs($this->admin);
        $data = $this->postJson('/api/v1/communications/bulk-email/estimate', [
            'recipient_filters' => ['module' => 'tribe'],
            'channels' => ['email', 'sms'],
            'text_body' => 'Hello {{first_name}}',
        ])->assertOk()->json('data');

        $this->assertSame(5, $data['matching_records']);
        $this->assertSame(5, $data['unique_people']);
        $this->assertSame(['eligible' => 3, 'invalid' => 1, 'suppressed' => 1], array_intersect_key($data['channel_stats']['email'], array_flip(['eligible', 'invalid', 'suppressed'])));
        $sms = $data['channel_stats']['sms'];
        $this->assertSame(2, $sms['eligible']);
        $this->assertSame(1, $sms['invalid']);
        $this->assertSame(1, $sms['duplicates_removed']);
        $this->assertSame(1, $sms['opted_out']);
        $this->assertSame(5, $data['total_messages']);
        $this->assertSame('not_applicable', $data['tenant_scope']);
        $this->assertSame(2, $data['costs']['sms']['messages']);
        $this->assertSame(1, $data['costs']['sms']['segments_per_message']);

        $json = json_encode($data['sample']);
        $this->assertStringNotContainsString('8031234567', (string) $json);
        $this->assertStringContainsString('duplicate_destination', (string) $json);
    }

    public function test_missing_contacts_are_reported_as_missing(): void
    {
        $event = $this->event('No Phone Event');
        $this->registration($event, 'nophone@example.com', null);

        $resolved = app(RecipientAudienceService::class)->resolve(['module' => 'events', 'event_id' => $event->uuid], ['email', 'sms']);

        $this->assertSame(1, $resolved['channels']['email']['eligible']);
        $this->assertSame(1, $resolved['channels']['sms']['missing']);
        $this->assertSame('missing_phone', $resolved['exclusions']['sms'][0]['reason']);
    }

    public function test_audience_modules_expose_real_modules_and_option_objects(): void
    {
        Sanctum::actingAs($this->admin);
        $modules = collect($this->getJson('/api/v1/communications/audience-modules')->assertOk()->json('data.modules'));

        foreach (['general', 'events', 'tribe', 'membership', 'visitors', 'lms', 'counseling', 'prayer', 'business_review'] as $id) {
            $this->assertTrue($modules->contains('id', $id), "Module {$id} missing");
        }
        $tribe = $modules->firstWhere('id', 'tribe');
        $statusFilter = collect($tribe['filters'])->firstWhere('key', 'status');
        $this->assertNotNull($statusFilter);
        $this->assertArrayHasKey('value', $statusFilter['options'][0]);
        $this->assertArrayHasKey('label', $statusFilter['options'][0]);
    }

    public function test_restricted_modules_require_their_own_permission(): void
    {
        $operator = $this->userWithPermissions(['communications.manage', 'events.manage']);
        Sanctum::actingAs($operator);

        $this->postJson('/api/v1/communications/bulk-email/estimate', ['recipient_filters' => ['module' => 'counseling']])->assertForbidden();
        $this->postJson('/api/v1/communications/bulk-email/estimate', ['recipient_filters' => ['module' => 'prayer']])->assertForbidden();
        $this->postJson('/api/v1/communications/bulk-email', [
            'subject' => 'x', 'html_body' => '<p>x</p>', 'recipient_filters' => ['module' => 'counseling'], 'action' => 'draft',
        ])->assertForbidden();
        $this->postJson('/api/v1/communications/bulk-email/estimate', ['recipient_filters' => ['module' => 'events']])->assertOk();

        $modules = collect($this->getJson('/api/v1/communications/audience-modules')->assertOk()->json('data.modules'));
        $this->assertFalse($modules->firstWhere('id', 'counseling')['allowed']);
        $this->assertTrue($modules->firstWhere('id', 'events')['allowed']);
    }

    public function test_unauthorized_users_cannot_use_communications_endpoints(): void
    {
        Sanctum::actingAs($this->memberUser());

        $this->getJson('/api/v1/communications/providers')->assertForbidden();
        $this->getJson('/api/v1/communications/consents')->assertForbidden();
        $this->getJson('/api/v1/communications/bulk-email')->assertForbidden();
        $this->postJson('/api/v1/communications/whatsapp-templates', ['provider' => 'meta'])->assertForbidden();
        $this->postJson('/api/v1/communications/bulk-email/test-send', ['channel' => 'sms', 'to' => '+2348031234567', 'subject' => 'x'])->assertForbidden();
    }

    // ── Consent ────────────────────────────────────────────────────────────

    public function test_consent_rules_separate_promotional_and_transactional(): void
    {
        $consent = app(CommunicationConsentService::class);
        $consent->record('email', 'Promo.Out@example.com', CommunicationConsent::OPTED_OUT, 'promotional', 'test');
        $consent->record('sms', '+2348031234567', CommunicationConsent::SUPPRESSED, 'all', 'test');

        $this->assertFalse($consent->evaluate('email', 'promo.out@example.com', 'promotional')['allowed']);
        $this->assertTrue($consent->evaluate('email', 'promo.out@example.com', 'transactional')['allowed']);
        $this->assertSame('suppressed', $consent->evaluate('sms', '+2348031234567', 'transactional')['reason']);
        $this->assertSame('no_opt_in', $consent->evaluate('whatsapp', '+2348030000000', 'transactional')['reason']);

        $consent->record('whatsapp', '+2348030000000', CommunicationConsent::OPTED_IN, 'promotional', 'event_form');
        $this->assertTrue($consent->evaluate('whatsapp', '+2348030000000', 'promotional')['allowed']);

        $this->assertSame(1, CommunicationConsent::query()->where('destination', '+2348030000000')->firstOrFail()->events()->count());
    }

    public function test_newsletter_unsubscribe_blocks_promotional_email(): void
    {
        Sanctum::actingAs($this->admin);
        $this->postJson('/api/v1/public/forms/newsletter/unsubscribe', ['email' => 'news.out@example.com'])->assertSuccessful();

        $this->assertFalse(app(CommunicationConsentService::class)->evaluate('email', 'news.out@example.com', 'promotional')['allowed']);
    }

    public function test_consent_api_requires_evidence_for_opt_in_and_records_history(): void
    {
        Sanctum::actingAs($this->admin);

        $this->postJson('/api/v1/communications/consents', [
            'channel' => 'whatsapp', 'destination' => '08031234567', 'status' => 'opted_in', 'source' => 'admin',
        ])->assertStatus(422);
        $this->postJson('/api/v1/communications/consents', [
            'channel' => 'sms', 'destination' => 'not-a-number', 'status' => 'opted_out', 'source' => 'admin',
        ])->assertStatus(422);

        $created = $this->postJson('/api/v1/communications/consents', [
            'channel' => 'whatsapp', 'destination' => '08031234567', 'status' => 'opted_in', 'source' => 'admin',
            'evidence' => 'Signed paper form at conference desk 2026-10-01',
        ])->assertCreated();

        $this->assertDatabaseHas('communication_consents', ['channel' => 'whatsapp', 'destination' => '+2348031234567', 'status' => 'opted_in']);
        $this->assertStringNotContainsString('8031234567', (string) $created->json('data.consent.destination'));
        $this->getJson('/api/v1/communications/consents/'.$created->json('data.consent.id'))
            ->assertOk()
            ->assertJsonCount(1, 'data.events');
    }

    // ── Campaign lifecycle ─────────────────────────────────────────────────

    public function test_draft_is_saved_without_dispatching(): void
    {
        Queue::fake();
        Sanctum::actingAs($this->admin);
        $this->member(['application_number' => 'T-1', 'email' => 'draft@example.com']);

        $res = $this->postJson('/api/v1/communications/bulk-email', $this->emailPayload(['action' => 'draft']))->assertCreated();

        $this->assertSame('draft', $res->json('data.job.status'));
        Queue::assertNothingPushed();
        $this->assertSame(0, BulkEmailRecipient::query()->count());
    }

    public function test_schedule_uses_africa_lagos_and_dispatch_due_queues_once(): void
    {
        Queue::fake();
        Sanctum::actingAs($this->admin);
        Carbon::setTestNow(Carbon::parse('2030-01-01 06:00:00', 'UTC'));
        $this->member(['application_number' => 'T-1', 'email' => 'sched@example.com']);

        $res = $this->postJson('/api/v1/communications/bulk-email', $this->emailPayload([
            'action' => 'schedule', 'scheduled_at' => '2030-01-01 09:00',
        ]))->assertCreated();

        $job = BulkEmailJob::query()->where('uuid', $res->json('data.job.id'))->firstOrFail();
        $this->assertSame('scheduled', $job->status);
        $this->assertSame('2030-01-01 08:00', $job->scheduled_at->copy()->utc()->format('Y-m-d H:i'));
        Queue::assertNothingPushed();

        Carbon::setTestNow(Carbon::parse('2030-01-01 07:59:00', 'UTC'));
        $this->assertSame(0, app(BulkEmailService::class)->dispatchDue());

        Carbon::setTestNow(Carbon::parse('2030-01-01 08:00:30', 'UTC'));
        $this->assertSame(1, app(BulkEmailService::class)->dispatchDue());
        $this->assertSame(0, app(BulkEmailService::class)->dispatchDue());
        Queue::assertPushed(SendBulkEmailBatchJob::class, 1);
        $this->assertSame('queued', $job->fresh()->status);
    }

    public function test_large_audience_requires_typed_confirmation_of_exact_total(): void
    {
        Queue::fake();
        config(['communications.campaigns.confirmation_threshold' => 2]);
        Sanctum::actingAs($this->admin);
        $this->member(['application_number' => 'T-1', 'email' => 'one@example.com']);
        $this->member(['application_number' => 'T-2', 'email' => 'two@example.com']);

        $this->postJson('/api/v1/communications/bulk-email', $this->emailPayload())->assertStatus(422)->assertJsonValidationErrors('confirm_total');
        $this->postJson('/api/v1/communications/bulk-email', $this->emailPayload(['confirm_total' => 3]))->assertStatus(422);
        $this->postJson('/api/v1/communications/bulk-email', $this->emailPayload(['confirm_total' => 2]))->assertCreated();
        Queue::assertPushed(SendBulkEmailBatchJob::class, 1);
    }

    public function test_empty_audience_and_unconfigured_provider_are_rejected(): void
    {
        Sanctum::actingAs($this->admin);

        $this->postJson('/api/v1/communications/bulk-email', $this->emailPayload())->assertStatus(422)->assertJsonValidationErrors('recipient_filters');

        $this->member(['application_number' => 'T-1', 'phone' => '08031234567']);
        $this->postJson('/api/v1/communications/bulk-email', $this->smsPayload())->assertStatus(422)->assertJsonValidationErrors('channels');
    }

    public function test_email_campaign_sends_with_unsubscribe_and_records_acceptance_not_delivery(): void
    {
        Mail::fake();
        Sanctum::actingAs($this->admin);
        $this->member(['application_number' => 'T-1', 'email' => 'mail.one@example.com', 'first_name' => 'Ada', 'display_name' => 'Ada Obi']);
        $this->member(['application_number' => 'T-2', 'email' => 'Mail.One@example.com', 'first_name' => 'Dup']);

        $res = $this->postJson('/api/v1/communications/bulk-email', $this->emailPayload([
            'subject' => 'Hello {{first_name}}', 'html_body' => '<p>Hi {{first_name}}</p><script>alert(1)</script>',
        ]))->assertCreated();

        Mail::assertSent(CommunicationMailable::class, 1);
        Mail::assertSent(CommunicationMailable::class, function (CommunicationMailable $mail): bool {
            return $mail->mailSubject === 'Hello Ada'
                && str_contains($mail->htmlBody, 'Unsubscribe')
                && ! str_contains($mail->htmlBody, '<script')
                && $mail->listUnsubscribeUrl !== null
                && str_contains((string) $mail->textBody, 'Hi Ada');
        });

        $job = BulkEmailJob::query()->where('uuid', $res->json('data.job.id'))->firstOrFail();
        $this->assertSame('completed', $job->status);
        $this->assertSame(1, $job->accepted_count);
        $this->assertSame(0, $job->delivered_count);
        $recipient = BulkEmailRecipient::query()->where('bulk_email_job_id', $job->id)->where('status', 'accepted')->firstOrFail();
        $this->assertNull($recipient->delivered_at);
        $this->assertSame(1, BulkEmailRecipient::query()->where('bulk_email_job_id', $job->id)->count());
        $this->assertSame(1, $job->preview_snapshot['send_time']['summary']['duplicate_records_merged']);
    }

    public function test_log_mailer_never_reports_campaign_success(): void
    {
        config(['mail.default' => 'log']);
        Sanctum::actingAs($this->admin);
        $this->member(['application_number' => 'T-1', 'email' => 'log.only@example.com']);

        $res = $this->postJson('/api/v1/communications/bulk-email', $this->emailPayload())->assertCreated();
        $job = BulkEmailJob::query()->where('uuid', $res->json('data.job.id'))->firstOrFail();

        $this->assertSame('failed', $job->status);
        $this->assertSame(0, $job->accepted_count);
        $this->assertDatabaseHas('bulk_email_recipients', ['bulk_email_job_id' => $job->id, 'status' => 'skipped', 'skip_reason' => 'mailer_not_delivering']);
    }

    public function test_sms_campaign_uses_termii_and_delivery_comes_only_from_signed_callback(): void
    {
        $this->useTermii();
        Sanctum::actingAs($this->admin);
        $this->member(['application_number' => 'T-1', 'phone' => '08031234567', 'first_name' => 'Tobi']);
        $this->member(['application_number' => 'T-2', 'phone' => '+447911123456', 'first_name' => 'Jane']);

        $res = $this->postJson('/api/v1/communications/bulk-email', $this->smsPayload(['text_body' => 'Hi {{first_name}}, see you Sunday.']))->assertCreated();
        $job = BulkEmailJob::query()->where('uuid', $res->json('data.job.id'))->firstOrFail();

        Http::assertSentCount(2);
        Http::assertSent(fn (HttpRequest $r) => $r['to'] === '2348031234567' && $r['sms'] === 'Hi Tobi, see you Sunday.' && $r['channel'] === 'generic');
        Http::assertSent(fn (HttpRequest $r) => $r['to'] === '447911123456');

        $this->assertSame('completed', $job->status);
        $this->assertSame(2, $job->accepted_count);
        $this->assertSame(0, $job->delivered_count);

        $recipient = BulkEmailRecipient::query()->where('bulk_email_job_id', $job->id)->where('destination', '+2348031234567')->firstOrFail();
        $this->assertSame('accepted', $recipient->status);
        $this->assertSame('termii', $recipient->provider);
        $this->assertSame(1, (int) $recipient->segments);

        $this->termiiCallback(['message_id' => $recipient->provider_message_id, 'status' => 'Delivered'])->assertOk();

        $this->assertSame('delivered', $recipient->fresh()->status);
        $this->assertNotNull($recipient->fresh()->delivered_at);
        $this->assertSame(1, $job->fresh()->delivered_count);
        $this->assertSame(1, $job->fresh()->accepted_count);
    }

    public function test_promotional_sms_never_uses_dnd_route(): void
    {
        $this->useTermii();
        config(['communications.termii.transactional_dnd_route' => true]);
        $outbound = app(OutboundMessageService::class);

        $outbound->send('sms', '+2348031234567', 'Promo', ['message_type' => 'promotional', 'skip_consent_check' => true]);
        $outbound->send('sms', '+2348031234568', 'OTP', ['message_type' => 'transactional']);

        Http::assertSent(fn (HttpRequest $r) => $r['sms'] === 'Promo' && $r['channel'] === 'generic');
        Http::assertSent(fn (HttpRequest $r) => $r['sms'] === 'OTP' && $r['channel'] === 'dnd');
    }

    public function test_duplicate_batch_jobs_do_not_resend(): void
    {
        $this->useTermii();
        Queue::fake();
        Sanctum::actingAs($this->admin);
        $this->member(['application_number' => 'T-1', 'phone' => '08031234567']);

        $res = $this->postJson('/api/v1/communications/bulk-email', $this->smsPayload())->assertCreated();
        $job = BulkEmailJob::query()->where('uuid', $res->json('data.job.id'))->firstOrFail();

        $campaigns = app(CampaignDispatchService::class);
        $campaigns->start($job->id);
        $campaigns->start($job->id);
        $ids = BulkEmailRecipient::query()->where('bulk_email_job_id', $job->id)->pluck('id')->all();
        $this->assertCount(1, $ids);

        $campaigns->sendBatch($job->id, 'sms', $ids);
        $campaigns->sendBatch($job->id, 'sms', $ids);

        Http::assertSentCount(1);
        $this->assertSame(1, CommunicationOutboundMessage::query()->count());
        $this->assertSame(1, (int) BulkEmailRecipient::query()->find($ids[0])->attempts);
    }

    public function test_transient_failures_retry_with_bounded_attempts(): void
    {
        $this->useTermii([
            Http::response(['message' => 'Rate limited'], 429),
            Http::response(['message_id' => 'tm-retry-ok', 'message' => 'Successfully Sent'], 200),
        ]);
        Sanctum::actingAs($this->admin);
        $this->member(['application_number' => 'T-1', 'phone' => '08031234567']);

        $res = $this->postJson('/api/v1/communications/bulk-email', $this->smsPayload())->assertCreated();
        $job = BulkEmailJob::query()->where('uuid', $res->json('data.job.id'))->firstOrFail();
        $recipient = BulkEmailRecipient::query()->where('bulk_email_job_id', $job->id)->firstOrFail();

        Http::assertSentCount(2);
        $this->assertSame('accepted', $recipient->status);
        $this->assertSame(2, (int) $recipient->attempts);
        $this->assertSame('tm-retry-ok', $recipient->provider_message_id);
        $this->assertSame(1, CommunicationOutboundMessage::query()->count());
        $this->assertSame('completed', $job->status);
    }

    public function test_persistent_transient_failure_stops_at_max_attempts(): void
    {
        $this->useTermii([Http::response(['message' => 'down'], 503)]);
        config(['communications.campaigns.max_attempts' => 3]);
        Sanctum::actingAs($this->admin);
        $this->member(['application_number' => 'T-1', 'phone' => '08031234567']);

        $res = $this->postJson('/api/v1/communications/bulk-email', $this->smsPayload())->assertCreated();
        $job = BulkEmailJob::query()->where('uuid', $res->json('data.job.id'))->firstOrFail();
        $recipient = BulkEmailRecipient::query()->where('bulk_email_job_id', $job->id)->firstOrFail();

        Http::assertSentCount(3);
        $this->assertSame('failed', $recipient->status);
        $this->assertSame(3, (int) $recipient->attempts);
        $this->assertSame('failed', $job->status);
    }

    public function test_permanent_failure_is_not_retried_and_mixed_results_are_partial(): void
    {
        $this->useTermii([
            Http::response(['message_id' => 'tm-ok', 'message' => 'Successfully Sent'], 200),
            Http::response(['message' => 'Invalid sender id'], 400),
        ]);
        Sanctum::actingAs($this->admin);
        $this->member(['application_number' => 'T-1', 'phone' => '08031234567']);
        $this->member(['application_number' => 'T-2', 'phone' => '08031234568']);

        $res = $this->postJson('/api/v1/communications/bulk-email', $this->smsPayload())->assertCreated();
        $job = BulkEmailJob::query()->where('uuid', $res->json('data.job.id'))->firstOrFail();

        Http::assertSentCount(2);
        $this->assertSame('partially_completed', $job->status);
        $failed = BulkEmailRecipient::query()->where('bulk_email_job_id', $job->id)->where('status', 'failed')->firstOrFail();
        $this->assertSame(1, (int) $failed->attempts);
        $this->assertSame('http_400', $failed->error_code);
        $this->assertSame(1, $job->failed_count);
        $this->assertSame(1, $job->accepted_count);
    }

    public function test_cancellation_stops_unsent_recipients(): void
    {
        $this->useTermii();
        Queue::fake();
        Sanctum::actingAs($this->admin);
        $this->member(['application_number' => 'T-1', 'phone' => '08031234567']);
        $this->member(['application_number' => 'T-2', 'phone' => '08031234568']);

        $res = $this->postJson('/api/v1/communications/bulk-email', $this->smsPayload())->assertCreated();
        $job = BulkEmailJob::query()->where('uuid', $res->json('data.job.id'))->firstOrFail();
        app(CampaignDispatchService::class)->start($job->id);
        $ids = BulkEmailRecipient::query()->where('bulk_email_job_id', $job->id)->pluck('id')->all();

        $this->postJson('/api/v1/communications/bulk-email/'.$job->uuid.'/cancel')->assertOk()->assertJsonPath('data.job.status', 'cancelled');
        app(CampaignDispatchService::class)->sendBatch($job->id, 'sms', $ids);

        Http::assertNothingSent();
        $this->assertSame(2, BulkEmailRecipient::query()->where('bulk_email_job_id', $job->id)->where('status', 'cancelled')->count());
        $this->postJson('/api/v1/communications/bulk-email/'.$job->uuid.'/cancel')->assertStatus(422);
    }

    public function test_resume_marks_interrupted_rows_failed_without_resending(): void
    {
        $this->useTermii();
        Queue::fake();
        Sanctum::actingAs($this->admin);
        $this->member(['application_number' => 'T-1', 'phone' => '08031234567']);
        $this->member(['application_number' => 'T-2', 'phone' => '08031234568']);

        $res = $this->postJson('/api/v1/communications/bulk-email', $this->smsPayload())->assertCreated();
        $job = BulkEmailJob::query()->where('uuid', $res->json('data.job.id'))->firstOrFail();
        app(CampaignDispatchService::class)->start($job->id);
        $stuck = BulkEmailRecipient::query()->where('bulk_email_job_id', $job->id)->orderBy('id')->firstOrFail();
        BulkEmailRecipient::query()->whereKey($stuck->id)->update(['status' => 'processing', 'attempts' => 1, 'updated_at' => now()->subHour()]);

        $this->postJson('/api/v1/communications/bulk-email/'.$job->uuid.'/resume')
            ->assertOk()
            ->assertJsonPath('data.interrupted', 1);

        $this->assertSame('failed', $stuck->fresh()->status);
        $this->assertSame('interrupted', $stuck->fresh()->error_code);
        Http::assertNothingSent();
    }

    public function test_send_time_revalidation_honours_opt_out_after_preview(): void
    {
        $this->useTermii();
        Queue::fake();
        Sanctum::actingAs($this->admin);
        $this->member(['application_number' => 'T-1', 'phone' => '08031234567']);

        $res = $this->postJson('/api/v1/communications/bulk-email', $this->smsPayload())->assertCreated();
        $job = BulkEmailJob::query()->where('uuid', $res->json('data.job.id'))->firstOrFail();
        $this->assertSame(1, $job->estimated_count);

        app(CommunicationConsentService::class)->record('sms', '+2348031234567', CommunicationConsent::OPTED_OUT, 'all', 'reply_stop_termii');
        app(CampaignDispatchService::class)->start($job->id);

        Http::assertNothingSent();
        $this->assertSame('failed', $job->fresh()->status);
        $this->assertDatabaseHas('bulk_email_recipients', ['bulk_email_job_id' => $job->id, 'status' => 'skipped', 'skip_reason' => 'opted_out']);
    }

    public function test_opt_out_between_claim_and_send_is_skipped(): void
    {
        $this->useTermii();
        Queue::fake();
        Sanctum::actingAs($this->admin);
        $this->member(['application_number' => 'T-1', 'phone' => '08031234567']);

        $res = $this->postJson('/api/v1/communications/bulk-email', $this->smsPayload())->assertCreated();
        $job = BulkEmailJob::query()->where('uuid', $res->json('data.job.id'))->firstOrFail();
        app(CampaignDispatchService::class)->start($job->id);
        app(CommunicationConsentService::class)->record('sms', '+2348031234567', CommunicationConsent::OPTED_OUT, 'promotional', 'admin');

        app(CampaignDispatchService::class)->sendBatch($job->id, 'sms', BulkEmailRecipient::query()->where('bulk_email_job_id', $job->id)->pluck('id')->all());

        Http::assertNothingSent();
        $this->assertDatabaseHas('bulk_email_recipients', ['bulk_email_job_id' => $job->id, 'status' => 'skipped', 'skip_reason' => 'opted_out']);
    }

    public function test_sync_queue_is_refused_in_production(): void
    {
        $this->member(['application_number' => 'T-1', 'email' => 'prod@example.com']);
        $job = app(BulkEmailService::class)->makeUnsaved(['subject' => 'x', 'html_body' => '<p>x</p>', 'recipient_filters' => ['module' => 'tribe']]);
        config(['queue.default' => 'sync']);
        $original = $this->app['env'];
        $this->app['env'] = 'production';
        try {
            $this->assertFalse(app(QueueHealthService::class)->canQueueCampaigns());
            $this->expectException(ValidationException::class);
            app(BulkEmailService::class)->assertSendable($job);
        } finally {
            $this->app['env'] = $original;
        }
    }

    public function test_queue_health_reports_stalled_worker(): void
    {
        config(['queue.default' => 'database']);
        Cache::put(QueueHealthService::WORKER_HEARTBEAT, now()->subMinutes(30)->toIso8601String());
        \DB::table('jobs')->insert([
            'queue' => 'default', 'payload' => '{}', 'attempts' => 0, 'reserved_at' => null,
            'available_at' => now()->subMinutes(20)->timestamp, 'created_at' => now()->subMinutes(20)->timestamp,
        ]);

        $snapshot = app(QueueHealthService::class)->snapshot();
        $this->assertSame('stalled', $snapshot['worker_status']);
        $this->assertSame(1, $snapshot['pending_jobs']);

        Cache::put(QueueHealthService::WORKER_HEARTBEAT, now()->toIso8601String());
        $this->assertSame('running', app(QueueHealthService::class)->snapshot()['worker_status']);

        Sanctum::actingAs($this->admin);
        $this->getJson('/api/v1/communications/providers')->assertOk()->assertJsonPath('data.providers.queue.worker_status', 'running');
    }

    // ── WhatsApp ───────────────────────────────────────────────────────────

    public function test_whatsapp_campaign_requires_approved_template_for_current_provider(): void
    {
        $this->useMeta();
        Sanctum::actingAs($this->admin);
        $this->member(['application_number' => 'T-1', 'phone' => '08031234567']);
        app(CommunicationConsentService::class)->record('whatsapp', '+2348031234567', CommunicationConsent::OPTED_IN, 'promotional', 'form');

        $this->postJson('/api/v1/communications/bulk-email', $this->whatsappPayload(null))->assertStatus(422)->assertJsonValidationErrors('whatsapp_template');

        $pending = $this->template(['status' => 'pending']);
        $this->postJson('/api/v1/communications/bulk-email', $this->whatsappPayload($pending))->assertStatus(422)->assertJsonValidationErrors('whatsapp_template');

        $twilioTemplate = $this->template(['provider' => 'twilio', 'name' => 'tw_template']);
        $this->postJson('/api/v1/communications/bulk-email', $this->whatsappPayload($twilioTemplate))->assertStatus(422);

        $marketing = $this->template(['name' => 'promo_template', 'category' => 'marketing']);
        $this->postJson('/api/v1/communications/bulk-email', $this->whatsappPayload($marketing, ['message_type' => 'transactional']))
            ->assertStatus(422)->assertJsonValidationErrors('whatsapp_template');
    }

    public function test_whatsapp_campaign_sends_template_only_to_opted_in_and_tracks_read(): void
    {
        $this->useMeta();
        Sanctum::actingAs($this->admin);
        $this->member(['application_number' => 'T-1', 'phone' => '08031234567', 'first_name' => 'Ngozi']);
        $this->member(['application_number' => 'T-2', 'phone' => '08031234568']);
        app(CommunicationConsentService::class)->record('whatsapp', '+2348031234567', CommunicationConsent::OPTED_IN, 'promotional', 'form');
        $template = $this->template(['parameters' => [['key' => '1', 'label' => 'Name', 'source' => 'variable:first_name']]]);

        $res = $this->postJson('/api/v1/communications/bulk-email', $this->whatsappPayload($template))->assertCreated();
        $job = BulkEmailJob::query()->where('uuid', $res->json('data.job.id'))->firstOrFail();

        Http::assertSentCount(1);
        Http::assertSent(fn (HttpRequest $r) => $r['type'] === 'template'
            && $r['to'] === '2348031234567'
            && $r['template']['name'] === 'event_update'
            && $r['template']['components'][0]['parameters'][0]['text'] === 'Ngozi');
        $this->assertDatabaseHas('bulk_email_recipients', ['bulk_email_job_id' => $job->id, 'status' => 'skipped', 'skip_reason' => 'no_opt_in']);

        $recipient = BulkEmailRecipient::query()->where('bulk_email_job_id', $job->id)->where('status', 'accepted')->firstOrFail();
        $this->metaCallback([['id' => $recipient->provider_message_id, 'status' => 'read', 'timestamp' => (string) now()->timestamp]])->assertOk();

        $this->assertSame('read', $recipient->fresh()->status);
        $this->assertNotNull($recipient->fresh()->delivered_at);
        $this->assertSame(1, $job->fresh()->delivered_count);
    }

    public function test_whatsapp_test_send_without_template_is_rejected(): void
    {
        $this->useMeta();
        Sanctum::actingAs($this->admin);

        $this->postJson('/api/v1/communications/bulk-email/test-send', [
            'channel' => 'whatsapp', 'to' => '+2348031234567', 'subject' => 'Test', 'text_body' => 'Hi',
        ])->assertStatus(422)->assertJsonPath('code', 'TEMPLATE_REQUIRED');
        Http::assertNothingSent();
    }

    public function test_twilio_template_registration_requires_content_sid(): void
    {
        Sanctum::actingAs($this->admin);
        $base = ['provider' => 'twilio', 'name' => 'event_update', 'language' => 'en', 'category' => 'utility', 'status' => 'approved'];

        $this->postJson('/api/v1/communications/whatsapp-templates', $base)->assertStatus(422)->assertJsonValidationErrors('provider_template_id');
        $this->postJson('/api/v1/communications/whatsapp-templates', [...$base, 'provider_template_id' => 'not-a-sid'])->assertStatus(422);
        $this->postJson('/api/v1/communications/whatsapp-templates', [...$base, 'name' => 'Bad Name'])->assertStatus(422);
        $this->postJson('/api/v1/communications/whatsapp-templates', [...$base, 'provider_template_id' => 'HX'.str_repeat('a', 32)])->assertCreated();
    }

    // ── Callbacks ──────────────────────────────────────────────────────────

    public function test_termii_callback_rejects_invalid_signature_without_side_effects(): void
    {
        config(['communications.termii.secret_key' => self::TERMII_SECRET]);

        $this->withHeaders(['X-Termii-Signature' => 'forged'])
            ->postJson('/api/v1/communications/webhooks/termii', ['message_id' => 'x', 'status' => 'Delivered'])
            ->assertForbidden();
        $this->postJson('/api/v1/communications/webhooks/termii', ['message_id' => 'x', 'status' => 'Delivered'])->assertForbidden();

        $this->assertSame(0, CommunicationDeliveryEvent::query()->count());
    }

    public function test_meta_callback_rejects_invalid_signature_and_verifies_subscription(): void
    {
        $this->useMeta();

        $this->withHeaders(['X-Hub-Signature-256' => 'sha256=forged'])
            ->postJson('/api/v1/communications/webhooks/meta', ['entry' => []])
            ->assertForbidden();

        $this->get('/api/v1/communications/webhooks/meta?hub_mode=subscribe&hub_verify_token=wrong&hub_challenge=123')->assertForbidden();
        $this->get('/api/v1/communications/webhooks/meta?hub_mode=subscribe&hub_verify_token=verify-me&hub_challenge=123')
            ->assertOk()->assertSee('123');
    }

    public function test_twilio_callback_validates_signature_and_applies_status(): void
    {
        $this->useTwilioSms();
        $row = app(OutboundMessageService::class)->send('sms', '+14155552671', 'Hello', ['message_type' => 'transactional']);
        $this->assertSame('accepted', $row->status);
        $this->assertSame('SM-test-1', $row->provider_message_id);

        $params = ['MessageSid' => 'SM-test-1', 'MessageStatus' => 'delivered', 'To' => '+14155552671', 'From' => '+15005550006'];
        $this->withHeaders(['X-Twilio-Signature' => 'bad'])->post('/api/v1/communications/webhooks/twilio', $params)->assertForbidden();
        $this->assertSame('accepted', $row->fresh()->status);

        $this->twilioCallback($params)->assertNoContent();
        $this->assertSame('delivered', $row->fresh()->status);
    }

    public function test_twilio_stop_reply_records_opt_out_for_all_messages(): void
    {
        $this->useTwilioSms();

        $this->twilioCallback(['MessageSid' => 'SM-in-1', 'From' => '+2348031234567', 'To' => '+15005550006', 'Body' => 'STOP'])->assertNoContent();

        $decision = app(CommunicationConsentService::class)->evaluate('sms', '+2348031234567', 'transactional');
        $this->assertFalse($decision['allowed']);
        $this->assertDatabaseHas('communication_consent_events', ['to_status' => 'opted_out', 'source' => 'reply_stop_twilio']);
    }

    public function test_callbacks_are_idempotent_and_never_downgrade(): void
    {
        $this->useTermii();
        $row = app(OutboundMessageService::class)->send('sms', '+2348031234567', 'Hello', ['message_type' => 'transactional']);
        $id = (string) $row->provider_message_id;

        $this->termiiCallback(['message_id' => $id, 'status' => 'Delivered'])->assertOk();
        $this->termiiCallback(['message_id' => $id, 'status' => 'Delivered'])->assertOk();
        $this->termiiCallback(['message_id' => $id, 'status' => 'Message Sent'])->assertOk();
        $this->termiiCallback(['message_id' => $id, 'status' => 'Failed'])->assertOk();

        $this->assertSame('delivered', $row->fresh()->status);
        $this->assertSame(3, CommunicationDeliveryEvent::query()->where('provider_message_id', $id)->count());
        $this->assertTrue(DeliveryStatus::canTransition('accepted', 'delivered'));
        $this->assertFalse(DeliveryStatus::canTransition('read', 'delivered'));
        $this->assertFalse(DeliveryStatus::canTransition('skipped', 'delivered'));
    }

    public function test_out_of_order_callback_is_reconciled_after_send_is_recorded(): void
    {
        $this->useTermii();
        $this->termiiCallback(['message_id' => 'tm-early', 'status' => 'Delivered'])->assertOk();
        $event = CommunicationDeliveryEvent::query()->where('provider_message_id', 'tm-early')->firstOrFail();
        $this->assertFalse((bool) $event->applied);

        $row = CommunicationOutboundMessage::query()->create([
            'channel' => 'sms', 'to' => '+2348031234567', 'status' => 'accepted', 'provider' => 'termii',
            'provider_message_id' => 'tm-early', 'body' => 'x', 'sent_at' => now(),
        ]);
        $this->assertSame(1, app(DeliveryStatusService::class)->reconcile());

        $this->assertSame('delivered', $row->fresh()->status);
        $this->assertTrue((bool) $event->fresh()->applied);
    }

    public function test_dnd_failure_callback_records_actionable_error(): void
    {
        $this->useTermii();
        $row = app(OutboundMessageService::class)->send('sms', '+2348031234567', 'Hello', ['message_type' => 'promotional', 'skip_consent_check' => true]);

        $this->termiiCallback(['message_id' => $row->provider_message_id, 'status' => 'DND Active on Phone Number'])->assertOk();

        $this->assertSame('failed', $row->fresh()->status);
        $this->assertSame('dnd_active', $row->fresh()->error_code);
    }

    public function test_signed_unsubscribe_link_opts_out_and_rejects_tampering(): void
    {
        $url = app(CampaignMessageRenderer::class)->unsubscribeUrl('email', 'Leave.Me@example.com');

        $this->get($url.'x')->assertForbidden();
        $this->get(str_replace('channel=email', 'channel=sms', $url))->assertForbidden();
        $this->get($url)->assertOk()->assertSee('unsubscribed');

        $consent = app(CommunicationConsentService::class);
        $this->assertFalse($consent->evaluate('email', 'leave.me@example.com', 'promotional')['allowed']);
        $this->assertTrue($consent->evaluate('email', 'leave.me@example.com', 'transactional')['allowed']);
    }

    // ── Provider configuration ─────────────────────────────────────────────

    public function test_provider_status_moves_to_verified_only_after_real_test_send(): void
    {
        $providers = app(ProviderConfigurationService::class);
        $this->assertSame('not_configured', $providers->channel('sms')['status']);

        config(['communications.sms_provider' => 'termii', 'communications.termii.api_key' => 'live-key-123456789']);
        $this->assertSame('missing_settings', $providers->channel('sms')['status']);
        $this->assertContains('TERMII_SENDER_ID', $providers->channel('sms')['missing']);

        $this->useTermii();
        Http::fake(['termii.test/api/get-balance*' => Http::response(['balance' => 1000, 'currency' => 'NGN'])]);
        $this->assertSame('configured_unverified', $providers->channel('sms')['status']);

        Sanctum::actingAs($this->admin);
        $this->postJson('/api/v1/communications/providers/sms/check')->assertOk();
        $this->assertSame('configured_unverified', $providers->channel('sms')['status']);

        $this->postJson('/api/v1/communications/channels/test', ['channel' => 'sms', 'to' => '+2348031234567', 'body' => 'Verify'])->assertOk();
        $this->assertSame('verified', $providers->channel('sms')['status']);

        config(['communications.termii.sender_id' => 'NewSender']);
        $this->assertSame('configured_unverified', $providers->channel('sms')['status']);
    }

    public function test_invalid_credentials_report_provider_error_not_success(): void
    {
        $this->useTermii([Http::response(['message' => 'Invalid API key'], 401)]);
        Sanctum::actingAs($this->admin);

        $res = $this->postJson('/api/v1/communications/channels/test', ['channel' => 'sms', 'to' => '+2348031234567', 'body' => 'Verify']);
        $res->assertStatus(422);
        $this->assertStringContainsString('not sent', strtolower((string) $res->json('message')));
        $this->assertSame('provider_error', app(ProviderConfigurationService::class)->channel('sms')['status']);
    }

    public function test_provider_api_never_returns_secrets(): void
    {
        $this->useTermii();
        $this->useMeta();
        Sanctum::actingAs($this->admin);

        $response = $this->getJson('/api/v1/communications/providers')->assertOk();
        $body = (string) $response->getContent();

        foreach (['termii-api-key-secret-value', self::TERMII_SECRET, self::META_SECRET, 'meta-token-secret-value', 'verify-me'] as $secret) {
            $this->assertStringNotContainsString($secret, $body);
        }
        $this->assertStringEndsWith('/api/v1/communications/webhooks/termii', (string) $response->json('data.providers.sms.webhook_urls.delivery_reports'));
    }

    // ── Reports ────────────────────────────────────────────────────────────

    public function test_recipient_report_and_csv_export_mask_destinations_and_neutralize_formulas(): void
    {
        $this->useTermii();
        Sanctum::actingAs($this->admin);
        $this->member(['application_number' => 'T-1', 'phone' => '08031234567', 'display_name' => '=HYPERLINK("http://evil")', 'first_name' => 'X']);

        $res = $this->postJson('/api/v1/communications/bulk-email', $this->smsPayload())->assertCreated();
        $uuid = $res->json('data.job.id');

        $list = $this->getJson('/api/v1/communications/bulk-email/'.$uuid.'/recipients')->assertOk();
        $this->assertStringNotContainsString('8031234567', (string) $list->getContent());
        $this->assertSame('accepted', $list->json('data.data.0.status'));
        $this->assertSame(1, $list->json('data.meta.total'));
        $this->getJson('/api/v1/communications/bulk-email')->assertOk()->assertJsonPath('data.data.0.id', $uuid);

        $csv = $this->get('/api/v1/communications/bulk-email/'.$uuid.'/export')->assertOk()->streamedContent();
        $this->assertStringNotContainsString('8031234567', $csv);
        $this->assertStringContainsString("'=HYPERLINK", $csv);

        $detail = $this->getJson('/api/v1/communications/bulk-email/'.$uuid)->assertOk();
        $this->assertSame(1, $detail->json('data.job.channel_breakdown.sms.accepted'));
    }

    public function test_test_send_endpoint_is_rate_limited(): void
    {
        Sanctum::actingAs($this->admin);
        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/v1/communications/channels/test', ['channel' => 'sms', 'to' => '+2348031234567'])->assertStatus(422);
        }
        $this->postJson('/api/v1/communications/channels/test', ['channel' => 'sms', 'to' => '+2348031234567'])->assertStatus(429);
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    /**
     * @param  list<\GuzzleHttp\Promise\PromiseInterface>|null  $sendResponses
     */
    private function useTermii(?array $sendResponses = null): void
    {
        config([
            'communications.sms_provider' => 'termii',
            'communications.termii.api_key' => 'termii-api-key-secret-value',
            'communications.termii.sender_id' => 'MMinistry',
            'communications.termii.secret_key' => self::TERMII_SECRET,
            'communications.termii.base_url' => 'https://termii.test',
            'communications.termii.transactional_dnd_route' => false,
        ]);
        $this->resetNotifiers();

        if ($sendResponses !== null) {
            $sequence = Http::sequence();
            foreach ($sendResponses as $response) {
                $sequence->pushResponse($response);
            }
            if (count($sendResponses) === 1) {
                $sequence->whenEmpty($sendResponses[0]);
            }
            Http::fake(['termii.test/api/sms/send' => $sequence]);

            return;
        }
        Http::fake(['termii.test/api/sms/send' => function (): \GuzzleHttp\Promise\PromiseInterface {
            $this->termiiCounter++;

            return Http::response(['message_id' => 'tm-'.$this->termiiCounter, 'message' => 'Successfully Sent', 'balance' => 99], 200);
        }]);
    }

    private function useMeta(): void
    {
        config([
            'communications.whatsapp_provider' => 'meta',
            'communications.meta_whatsapp.token' => 'meta-token-secret-value',
            'communications.meta_whatsapp.phone_number_id' => '1234567890',
            'communications.meta_whatsapp.business_account_id' => '9876543210',
            'communications.meta_whatsapp.app_secret' => self::META_SECRET,
            'communications.meta_whatsapp.verify_token' => 'verify-me',
            'communications.meta_whatsapp.graph_version' => 'v21.0',
        ]);
        $this->resetNotifiers();
        Http::fake(['graph.facebook.com/*/messages' => fn () => Http::response(['messages' => [['id' => 'wamid.'.Str::random(12)]]], 200)]);
    }

    private function useTwilioSms(): void
    {
        config([
            'communications.sms_provider' => 'twilio',
            'communications.twilio.account_sid' => 'AC'.str_repeat('1', 32),
            'communications.twilio.auth_token' => self::TWILIO_TOKEN,
            'communications.twilio.from' => '+15005550006',
            'communications.twilio.webhook_url' => 'https://api.example.org/api/v1/communications/webhooks/twilio',
        ]);
        $this->resetNotifiers();
        Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'SM-test-1', 'status' => 'queued'], 201)]);
    }

    private function resetNotifiers(): void
    {
        $this->app->forgetInstance(SmsNotifierContract::class);
        $this->app->forgetInstance(WhatsAppNotifierContract::class);
        $this->app->forgetInstance(OutboundMessageService::class);
        $this->app->forgetInstance(CampaignDispatchService::class);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function termiiCallback(array $payload): \Illuminate\Testing\TestResponse
    {
        return $this->withHeaders(['X-Termii-Signature' => hash_hmac('sha512', (string) json_encode($payload), self::TERMII_SECRET)])
            ->postJson('/api/v1/communications/webhooks/termii', $payload);
    }

    /**
     * @param  list<array<string, mixed>>  $statuses
     */
    private function metaCallback(array $statuses): \Illuminate\Testing\TestResponse
    {
        $payload = ['object' => 'whatsapp_business_account', 'entry' => [['changes' => [['field' => 'messages', 'value' => ['statuses' => $statuses]]]]]];

        return $this->withHeaders(['X-Hub-Signature-256' => 'sha256='.hash_hmac('sha256', (string) json_encode($payload), self::META_SECRET)])
            ->postJson('/api/v1/communications/webhooks/meta', $payload);
    }

    /**
     * @param  array<string, string>  $params
     */
    private function twilioCallback(array $params): \Illuminate\Testing\TestResponse
    {
        ksort($params);
        $data = '';
        foreach ($params as $key => $value) {
            $data .= $key.$value;
        }
        $signature = base64_encode(hash_hmac('sha1', 'https://api.example.org/api/v1/communications/webhooks/twilio'.$data, self::TWILIO_TOKEN, true));

        return $this->withHeaders(['X-Twilio-Signature' => $signature])->post('/api/v1/communications/webhooks/twilio', $params);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function emailPayload(array $overrides = []): array
    {
        return array_merge([
            'subject' => 'Tribe update',
            'html_body' => '<p>Hello {{first_name}}</p>',
            'channels' => ['email'],
            'message_type' => 'promotional',
            'recipient_filters' => ['module' => 'tribe'],
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function smsPayload(array $overrides = []): array
    {
        return array_merge([
            'subject' => 'Tribe SMS',
            'text_body' => 'Hello {{first_name}}',
            'channels' => ['sms'],
            'message_type' => 'promotional',
            'recipient_filters' => ['module' => 'tribe'],
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function whatsappPayload(?CommunicationWhatsAppTemplate $template, array $overrides = []): array
    {
        return array_merge([
            'subject' => 'Tribe WhatsApp',
            'channels' => ['whatsapp'],
            'message_type' => 'promotional',
            'recipient_filters' => ['module' => 'tribe'],
            'whatsapp_template' => $template ? ['template_id' => $template->uuid] : null,
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function template(array $overrides = []): CommunicationWhatsAppTemplate
    {
        return CommunicationWhatsAppTemplate::query()->create(array_merge([
            'uuid' => (string) Str::uuid(),
            'provider' => 'meta',
            'name' => 'event_update',
            'language' => 'en',
            'category' => 'utility',
            'status' => 'approved',
            'parameters' => [],
            'is_active' => true,
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function member(array $attributes): Member
    {
        return Member::factory()->create(array_merge(['email' => fake()->unique()->safeEmail(), 'phone' => null], $attributes));
    }

    private function event(string $title): Event
    {
        return Event::query()->create([
            'title' => $title,
            'slug' => Str::slug($title).'-'.uniqid(),
            'starts_at' => now(),
            'ends_at' => now()->addDay(),
            'timezone' => 'UTC',
            'visibility' => EventVisibility::Public,
            'status' => EventStatus::Published,
            'published_at' => now(),
        ]);
    }

    private function registration(Event $event, string $email, ?string $phone): EventRegistration
    {
        $person = Person::factory()->create(['email' => $email, 'phone' => $phone, 'display_name' => 'Reg '.$email]);

        return EventRegistration::query()->create([
            'event_id' => $event->id,
            'person_id' => $person->id,
            'status' => 'approved',
            'registration_number' => 'REG-'.strtoupper(Str::random(8)),
            'guest_email' => $email,
            'guest_phone' => $phone,
            'guest_name' => 'Reg '.$email,
            'consent_accepted' => true,
            'submitted_at' => now(),
        ]);
    }

    /**
     * @param  list<string>  $slugs
     */
    private function userWithPermissions(array $slugs): User
    {
        $role = Role::query()->create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Comms operator '.Str::random(4),
            'slug' => 'comms_operator_'.Str::lower(Str::random(6)),
            'guard_name' => 'web',
            'is_system' => false,
        ]);
        $role->permissions()->sync(Permission::query()->whereIn('slug', $slugs)->pluck('id'));
        $user = User::factory()->create();
        $user->roles()->sync([$role->id]);

        return $user;
    }
}
