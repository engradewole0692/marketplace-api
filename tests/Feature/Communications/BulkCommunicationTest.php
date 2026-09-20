<?php

declare(strict_types=1);

namespace Tests\Feature\Communications;

use App\Models\Person;
use App\Models\User;
use App\Modules\Communications\Services\OutboundMessageService;
use App\Modules\Communications\Services\RecipientAudienceService;
use App\Modules\Events\Enums\EventStatus;
use App\Modules\Events\Enums\EventVisibility;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Models\EventRegistration;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Iam\IamTestCase;

final class BulkCommunicationTest extends IamTestCase
{
    public function test_audience_modules_and_preview_deduplicate_people(): void
    {
        Sanctum::actingAs($this->admin);

        $this->getJson('/api/v1/communications/audience-modules')
            ->assertOk()
            ->assertJsonPath('data.modules.0.id', 'general');

        $event = Event::query()->create([
            'title' => 'Comms Event',
            'slug' => 'comms-event-'.uniqid(),
            'starts_at' => now(),
            'ends_at' => now()->addDay(),
            'timezone' => 'UTC',
            'visibility' => EventVisibility::Public,
            'status' => EventStatus::Published,
            'published_at' => now(),
        ]);
        $person = Person::factory()->create([
            'email' => 'same.person@example.com',
            'phone' => '08031234567',
            'display_name' => 'Same Person',
        ]);
        EventRegistration::query()->create([
            'event_id' => $event->id,
            'person_id' => $person->id,
            'status' => 'approved',
            'registration_number' => 'REG-COMMS-1',
            'guest_email' => 'same.person@example.com',
            'guest_name' => 'Same Person',
            'consent_accepted' => true,
            'submitted_at' => now(),
        ]);

        $deduped = app(RecipientAudienceService::class)->dedupe(collect([
            ['email' => 'same.person@example.com', 'phone' => null, 'name' => 'A', 'user_id' => 1, 'person_id' => $person->id],
            ['email' => 'same.person@example.com', 'phone' => null, 'name' => 'A', 'user_id' => 1, 'person_id' => $person->id],
        ]));
        $this->assertCount(1, $deduped);

        $preview = $this->postJson('/api/v1/communications/bulk-email/estimate', [
            'recipient_filters' => [
                'module' => 'events',
                'event_id' => $event->uuid,
                'channel' => 'email',
            ],
        ])->assertOk()->json('data');

        $this->assertSame(1, $preview['deduplicated_count']);
        $this->assertSame(1, $preview['valid_count']);
        $this->assertSame(0, $preview['invalid_count']);
        $this->assertNotEmpty($preview['sample']);
    }

    public function test_recipient_search_and_single_email(): void
    {
        Mail::fake();
        Sanctum::actingAs($this->admin);
        $person = Person::factory()->create([
            'email' => 'search.me@example.com',
            'phone' => '+233241234567',
            'display_name' => 'Search Target',
        ]);

        $this->getJson('/api/v1/communications/recipients/search?q=search.me@example.com')
            ->assertOk()
            ->assertJsonFragment(['email' => 'search.me@example.com']);

        $this->postJson('/api/v1/communications/send', [
            'channel' => 'email',
            'person_id' => $person->uuid,
            'subject' => 'Hello',
            'html_body' => '<p>Hello</p>',
            'text_body' => 'Hello',
        ])->assertCreated();

        Mail::assertSentCount(1);
    }

    public function test_general_audience_preview_counts_users(): void
    {
        Sanctum::actingAs($this->admin);
        User::factory()->create(['email' => 'audience.target@example.com']);

        $preview = $this->postJson('/api/v1/communications/bulk-email/estimate', [
            'recipient_filters' => [
                'module' => 'general',
                'audience' => 'all',
                'channel' => 'email',
            ],
        ])->assertOk()->json('data');

        $this->assertGreaterThanOrEqual(1, $preview['valid_count']);
    }

    public function test_sms_and_whatsapp_without_provider_do_not_claim_delivery(): void
    {
        Sanctum::actingAs($this->admin);

        $sms = $this->postJson('/api/v1/communications/channels/test', [
            'channel' => 'sms',
            'to' => '+233241234567',
            'body' => 'Test SMS',
        ]);
        $sms->assertStatus(422);
        $this->assertStringContainsString('not sent', strtolower($sms->json('message')));

        $wa = $this->postJson('/api/v1/communications/channels/test', [
            'channel' => 'whatsapp',
            'to' => '+233241234567',
            'body' => 'Test WhatsApp',
        ]);
        $wa->assertStatus(422);

        $row = app(OutboundMessageService::class)->send('sms', '+233241234567', 'Hello');
        $this->assertNotSame('sent', $row->status);
    }

    public function test_bulk_job_queues_and_activity_is_logged(): void
    {
        Mail::fake();
        Sanctum::actingAs($this->admin);
        User::factory()->create(['email' => 'bulk.one@example.com', 'status' => 'active']);

        $create = $this->postJson('/api/v1/communications/bulk-email', [
            'subject' => 'Bulk hello',
            'html_body' => '<p>Hi</p>',
            'text_body' => 'Hi',
            'channel' => 'email',
            'recipient_filters' => [
                'module' => 'general',
                'audience' => 'all',
                'channel' => 'email',
            ],
        ])->assertCreated();

        $this->assertContains($create->json('data.job.status'), ['queued', 'sending', 'completed']);
        $this->assertDatabaseHas('bulk_email_jobs', [
            'uuid' => $create->json('data.job.id'),
        ]);

        $this->getJson('/api/v1/communications/activity')
            ->assertOk()
            ->assertJsonStructure(['data' => ['items', 'meta']]);
    }

    public function test_unauthorized_user_cannot_send_bulk_or_search(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/communications/recipients/search?q=ab')->assertForbidden();
        $this->postJson('/api/v1/communications/bulk-email/estimate', [
            'recipient_filters' => ['module' => 'general'],
        ])->assertForbidden();
    }
}
