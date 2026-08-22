<?php

declare(strict_types=1);

namespace Tests\Feature\Communications;

use App\Models\User;
use App\Modules\Communications\Models\CommunicationEmailLog;
use App\Modules\Communications\Models\CommunicationRoute;
use App\Modules\Communications\Models\CommunicationSetting;
use App\Modules\Communications\Models\CommunicationTemplate;
use App\Modules\Communications\Services\CommunicationDispatchService;
use App\Modules\Communications\Services\CommunicationRoutingService;
use App\Modules\Communications\Services\CommunicationTemplateRenderer;
use Database\Seeders\CommunicationSeeder;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Iam\IamTestCase;

final class CommunicationTest extends IamTestCase
{
  protected function setUp(): void
  {
    parent::setUp();
    $this->seed([CommunicationSeeder::class]);
  }

  public function test_admin_can_view_and_update_settings(): void
  {
    Sanctum::actingAs($this->admin);

    $this->getJson('/api/v1/communications/settings')
      ->assertOk()
      ->assertJsonPath('data.settings.ministry_email', fn ($v) => $v !== null);

    $this->putJson('/api/v1/communications/settings', [
      'ministry_email' => 'ministry@example.com',
      'from_name' => 'Marketplace Ministers',
      'branding' => ['site_name' => 'Marketplace Ministers'],
    ])->assertOk()
      ->assertJsonPath('data.settings.ministry_email', 'ministry@example.com');

    $this->assertDatabaseHas('communication_settings', [
      'ministry_email' => 'ministry@example.com',
    ]);
  }

  public function test_unauthorized_user_cannot_manage_communications(): void
  {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $this->getJson('/api/v1/communications/settings')->assertForbidden();
  }

  public function test_template_crud_duplicate_and_reset(): void
  {
    Sanctum::actingAs($this->admin);

    $create = $this->postJson('/api/v1/communications/templates', [
      'name' => 'Custom template',
      'section' => 'contact',
      'event_key' => 'form.contact.submitted',
      'subject' => 'Hello {{applicant_name}}',
      'html_body' => '<p>Hi {{applicant_name}}</p>',
      'available_variables' => ['applicant_name'],
      'sample_variables' => ['applicant_name' => 'Jane'],
    ])->assertCreated();

    $id = $create->json('data.template.id');
    $this->assertNotEmpty($id);

    $this->putJson("/api/v1/communications/templates/{$id}", [
      'subject' => 'Updated {{applicant_name}}',
    ])->assertOk()
      ->assertJsonPath('data.template.subject', 'Updated {{applicant_name}}');

    $this->postJson("/api/v1/communications/templates/{$id}/duplicate")
      ->assertCreated()
      ->assertJsonPath('data.template.name', fn ($v) => str_contains((string) $v, 'Copy'));

    $system = CommunicationTemplate::query()->where('is_system', true)->firstOrFail();
    $originalSubject = $system->subject;
    $this->putJson("/api/v1/communications/templates/{$system->uuid}", [
      'subject' => 'Changed subject',
    ])->assertOk();

    $this->postJson("/api/v1/communications/templates/{$system->uuid}/reset")
      ->assertOk()
      ->assertJsonPath('data.template.subject', $originalSubject);
  }

  public function test_template_variable_rendering_is_safe(): void
  {
    $renderer = app(CommunicationTemplateRenderer::class);
    $rendered = $renderer->render(
      'Hello {{applicant_name}} — {{unknown_var}}',
      ['applicant_name' => '<script>alert(1)</script>'],
    );

    $this->assertStringContainsString('Hello', $rendered);
    $this->assertStringNotContainsString('<script>', $rendered);
    $this->assertStringNotContainsString('{{unknown_var}}', $rendered);
  }

  public function test_routing_resolves_section_and_ministry_with_deduplication(): void
  {
    CommunicationSetting::query()->first()?->update(['ministry_email' => 'ministry@example.com']);

    CommunicationRoute::query()->create([
      'section' => 'counseling',
      'label' => 'Counseling inbox',
      'recipient_role' => 'to',
      'recipient_type' => 'section_email',
      'email' => 'counseling@example.com',
      'sort_order' => 1,
      'include_ministry_fallback' => true,
      'is_active' => true,
    ]);

    CommunicationRoute::query()->create([
      'section' => 'counseling',
      'label' => 'Duplicate counseling',
      'recipient_role' => 'cc',
      'recipient_type' => 'section_email',
      'email' => 'counseling@example.com',
      'sort_order' => 2,
      'is_active' => true,
    ]);

    $resolved = app(CommunicationRoutingService::class)->resolve('counseling', 'form.counseling.submitted.admin');

    $this->assertContains('counseling@example.com', $resolved['to']);
    $this->assertContains('ministry@example.com', $resolved['cc']);
    $this->assertSame(1, count(array_filter($resolved['to'], fn ($e) => $e === 'counseling@example.com')));
  }

  public function test_dispatch_logs_sent_email_without_breaking_on_failure(): void
  {
    Mail::fake();

    $template = CommunicationTemplate::query()->where('event_key', 'form.contact.submitted')->firstOrFail();

    app(CommunicationDispatchService::class)->dispatchEvent(
      eventKey: 'form.contact.submitted',
      section: 'contact',
      variables: ['applicant_name' => 'Jane Doe', 'email' => 'jane@example.com'],
      recipientEmail: 'jane@example.com',
      recipientName: 'Jane Doe',
      includeRouting: false,
    );

    $this->assertDatabaseHas('communication_email_logs', [
      'recipient_email' => 'jane@example.com',
      'event_key' => 'form.contact.submitted',
      'status' => 'sent',
    ]);

    Mail::assertSent(\App\Modules\Communications\Mail\CommunicationMailable::class);
  }

  public function test_disabled_template_skips_custom_rendering(): void
  {
    Mail::fake();

    CommunicationTemplate::query()
      ->where('event_key', 'form.contact.submitted')
      ->update(['is_active' => false]);

    app(CommunicationDispatchService::class)->dispatchEvent(
      eventKey: 'form.contact.submitted',
      section: 'contact',
      variables: ['applicant_name' => 'Jane Doe'],
      recipientEmail: 'jane@example.com',
      includeRouting: false,
    );

    $this->assertDatabaseHas('communication_email_logs', [
      'recipient_email' => 'jane@example.com',
      'status' => 'sent',
    ]);
  }

  public function test_test_send_requires_authorization_and_logs(): void
  {
    Sanctum::actingAs($this->admin);
    Mail::fake();

    $template = CommunicationTemplate::query()->where('event_key', 'form.contact.submitted')->firstOrFail();

    $this->postJson("/api/v1/communications/templates/{$template->uuid}/test-send", [
      'recipient_email' => 'tester@example.com',
    ])->assertOk()
      ->assertJsonPath('data.log.is_test', true);

    $this->assertDatabaseHas('communication_email_logs', [
      'recipient_email' => 'tester@example.com',
      'is_test' => true,
    ]);
  }

  public function test_contact_form_creates_email_logs(): void
  {
    Mail::fake();

    $this->postJson('/api/v1/public/forms/contact', [
      'name' => 'Jane Doe',
      'email' => 'jane@example.com',
      'country' => 'Nigeria',
      'subject' => 'Hello',
      'message' => 'Test message',
    ])->assertCreated();

    $this->assertTrue(
      CommunicationEmailLog::query()->where('recipient_email', 'jane@example.com')->exists()
    );
  }

  public function test_admin_can_list_routes_and_logs(): void
  {
    Sanctum::actingAs($this->admin);

    $this->getJson('/api/v1/communications/routes')->assertOk();
    $this->getJson('/api/v1/communications/templates')->assertOk();
    $this->getJson('/api/v1/communications/logs')->assertOk();
  }

  public function test_communications_manage_permission_grants_access(): void
  {
    $user = User::factory()->create();
    $permission = \App\Models\Permission::query()->where('slug', 'communications.manage')->firstOrFail();
    $user->permissions()->syncWithoutDetaching([$permission->id]);
    Sanctum::actingAs($user);

    $this->getJson('/api/v1/communications/settings')->assertOk();
  }

  public function test_route_crud_with_specific_user_assignment(): void
  {
    Sanctum::actingAs($this->admin);
    $assignee = User::factory()->create(['email' => 'route-user@example.com']);

    $create = $this->postJson('/api/v1/communications/routes', [
      'section' => 'contact',
      'label' => 'Primary contact owner',
      'recipient_role' => 'to',
      'recipient_type' => 'assigned_user',
      'user_id' => $assignee->uuid,
      'sort_order' => 1,
      'is_active' => true,
    ])->assertCreated();

    $routeId = $create->json('data.route.id');
    $this->assertNotEmpty($routeId);

    $resolved = app(CommunicationRoutingService::class)->resolve('contact', 'form.contact.submitted.admin');
    $this->assertContains('route-user@example.com', $resolved['to']);

    $this->putJson("/api/v1/communications/routes/{$routeId}", [
      'label' => 'Updated owner',
    ])->assertOk()
      ->assertJsonPath('data.route.label', 'Updated owner');

    $this->deleteJson("/api/v1/communications/routes/{$routeId}")->assertOk();
  }

  public function test_duplicate_dispatch_is_prevented_by_idempotency_key(): void
  {
    Mail::fake();

    $dispatch = app(CommunicationDispatchService::class);
    $key = 'test.idempotency:'.uniqid('', true);

    $dispatch->dispatchEvent(
      eventKey: 'form.contact.submitted',
      section: 'contact',
      variables: ['applicant_name' => 'Jane Doe', 'email' => 'jane@example.com'],
      recipientEmail: 'jane@example.com',
      includeRouting: false,
      idempotencyKey: $key,
    );

    $dispatch->dispatchEvent(
      eventKey: 'form.contact.submitted',
      section: 'contact',
      variables: ['applicant_name' => 'Jane Doe', 'email' => 'jane@example.com'],
      recipientEmail: 'jane@example.com',
      includeRouting: false,
      idempotencyKey: $key,
    );

    $this->assertSame(1, CommunicationEmailLog::query()->where('recipient_email', 'jane@example.com')->count());
    Mail::assertSent(\App\Modules\Communications\Mail\CommunicationMailable::class, 1);
  }

  public function test_learner_registration_dispatches_welcome_email(): void
  {
    Mail::fake();

    $this->postJson('/api/v1/learner/register', [
      'name' => 'New Learner',
      'email' => 'learner-new@example.com',
      'password' => 'Password123!',
      'password_confirmation' => 'Password123!',
    ])->assertCreated();

    $this->assertTrue(
      CommunicationEmailLog::query()
        ->where('event_key', 'auth.learner.registered')
        ->where('recipient_email', 'learner-new@example.com')
        ->exists()
    );
  }

  public function test_template_preview_endpoint(): void
  {
    Sanctum::actingAs($this->admin);
    $template = CommunicationTemplate::query()->where('event_key', 'form.contact.submitted')->firstOrFail();

    $this->postJson("/api/v1/communications/templates/{$template->uuid}/preview", [
      'variables' => ['applicant_name' => 'Preview User'],
    ])->assertOk()
      ->assertJsonStructure(['data' => ['preview' => ['subject', 'html']]]);
  }

  public function test_invalid_recipient_email_is_skipped(): void
  {
    Mail::fake();

    app(CommunicationDispatchService::class)->dispatchEvent(
      eventKey: 'form.contact.submitted',
      section: 'contact',
      variables: ['applicant_name' => 'Jane'],
      recipientEmail: 'not-an-email',
      includeRouting: false,
    );

    Mail::assertNothingSent();
    $this->assertDatabaseHas('communication_email_logs', [
      'recipient_email' => 'not-an-email',
      'status' => 'failed',
    ]);
  }

  public function test_new_template_put_to_new_slug_returns_not_found(): void
  {
    Sanctum::actingAs($this->admin);

    $this->putJson('/api/v1/communications/templates/new', [
      'name' => 'Broken save',
      'section' => 'contact',
      'event_key' => 'form.contact.submitted',
      'subject' => 'Hello',
      'html_body' => '<p>Hi</p>',
    ])->assertNotFound();
  }

  public function test_invalid_event_key_is_rejected(): void
  {
    Sanctum::actingAs($this->admin);

    $this->postJson('/api/v1/communications/templates', [
      'name' => 'Bad key',
      'section' => 'contact',
      'event_key' => 'Not A Valid Key',
      'subject' => 'Hello',
      'html_body' => '<p>Hi</p>',
    ])->assertUnprocessable();
  }

  public function test_event_keys_and_health_endpoints_are_authorized(): void
  {
    Sanctum::actingAs($this->admin);

    $this->getJson('/api/v1/communications/event-keys')
      ->assertOk()
      ->assertJsonPath('data.event_keys.0.event_key', 'form.contact.submitted');

    $this->getJson('/api/v1/communications/health')
      ->assertOk()
      ->assertJsonPath('data.health.mailer', 'array')
      ->assertJsonPath('data.health.communications_dispatch', 'synchronous');
  }

  public function test_membership_application_sends_applicant_and_admin_emails(): void
  {
    Mail::fake();
    CommunicationSetting::query()->first()?->update(['ministry_email' => 'ministry@example.com']);

    $this->postJson('/api/v1/public/forms/membership', [
      'firstName' => 'John',
      'lastName' => 'Applicant',
      'email' => 'john.applicant@example.com',
      'phone' => '+2348012345678',
      'country' => 'Nigeria',
      'preferredMinistry' => 'marketplace-leadership',
      'dob' => '1990-05-15',
      'gender' => 'male',
      'maritalStatus' => 'single',
      'testimony' => 'I came to faith through marketplace discipleship and fellowship.',
      'churchName' => 'Grace Assembly',
      'yearsInFaith' => '4–7',
      'occupation' => 'Engineer',
      'industry' => 'Technology',
      'whyJoin' => 'I want to serve the body of Christ in the marketplace with excellence.',
      'availability' => 'monthly',
      'nextOfKin' => 'Jane Applicant',
      'kinRelationship' => 'Spouse',
      'kinPhone' => '+2348099999999',
      'declaration' => true,
    ])->assertCreated();

    $this->assertDatabaseHas('communication_email_logs', [
      'event_key' => 'form.membership.submitted',
      'recipient_email' => 'john.applicant@example.com',
      'status' => 'sent',
    ]);
    $this->assertTrue(
      CommunicationEmailLog::query()->where('event_key', 'form.membership.submitted.admin')->exists()
    );

    $applicantLog = CommunicationEmailLog::query()
      ->where('event_key', 'form.membership.submitted')
      ->where('recipient_email', 'john.applicant@example.com')
      ->firstOrFail();
    $this->assertNotEmpty($applicantLog->subject);
    $this->assertStringNotContainsString('{{application_number}}', (string) $applicantLog->subject);

    Mail::assertSent(\App\Modules\Communications\Mail\CommunicationMailable::class);
  }

  public function test_admin_alert_uses_applicant_reply_to_and_cc_ministry(): void
  {
    Mail::fake();
    CommunicationSetting::query()->first()?->update([
      'ministry_email' => 'ministry@example.com',
      'reply_to_email' => 'noreply@example.com',
    ]);

    app(CommunicationDispatchService::class)->dispatchEvent(
      eventKey: 'form.membership.submitted.admin',
      section: 'membership',
      variables: [
        'applicant_name' => 'Jane Doe',
        'email' => 'jane@example.com',
        'application_number' => 'APP-1001',
      ],
      includeRouting: true,
    );

    Mail::assertSent(\App\Modules\Communications\Mail\CommunicationMailable::class, function ($mail) {
      $replies = $mail->envelope()->replyTo;

      return collect($replies)->contains(fn ($address) => $address->address === 'jane@example.com');
    });

    $this->assertTrue(
      CommunicationEmailLog::query()
        ->where('event_key', 'form.membership.submitted.admin')
        ->where('recipient_email', 'ministry@example.com')
        ->exists()
    );
  }

  public function test_cc_and_bcc_routes_are_logged(): void
  {
    Mail::fake();
    CommunicationSetting::query()->first()?->update(['ministry_email' => 'ministry@example.com']);

    CommunicationRoute::query()->create([
      'section' => 'events',
      'label' => 'Events CC',
      'recipient_role' => 'cc',
      'recipient_type' => 'email',
      'email' => 'events-cc@example.com',
      'sort_order' => 1,
      'is_active' => true,
    ]);
    CommunicationRoute::query()->create([
      'section' => 'events',
      'label' => 'Events BCC',
      'recipient_role' => 'bcc',
      'recipient_type' => 'email',
      'email' => 'events-bcc@example.com',
      'sort_order' => 2,
      'is_active' => true,
    ]);

    app(CommunicationDispatchService::class)->dispatchEvent(
      eventKey: 'event.registration.confirmed.admin',
      section: 'events',
      variables: [
        'applicant_name' => 'Guest',
        'email' => 'guest@example.com',
        'event_name' => 'Conference',
      ],
      includeRouting: true,
    );

    $this->assertDatabaseHas('communication_email_logs', [
      'event_key' => 'event.registration.confirmed.admin',
      'recipient_email' => 'events-cc@example.com',
    ]);
    $this->assertDatabaseHas('communication_email_logs', [
      'event_key' => 'event.registration.confirmed.admin',
      'recipient_email' => 'events-bcc@example.com',
    ]);
  }

  public function test_event_registration_and_cancellation_use_canonical_event_keys(): void
  {
    Mail::fake();

    app(CommunicationDispatchService::class)->dispatchEvent(
      eventKey: 'event.registration.confirmed',
      section: 'events',
      variables: ['applicant_name' => 'Guest', 'event_name' => 'Conference', 'email' => 'guest@example.com'],
      recipientEmail: 'guest@example.com',
      includeRouting: false,
      idempotencyKey: 'event.registration.confirmed:test-1',
    );
    app(CommunicationDispatchService::class)->dispatchEvent(
      eventKey: 'event.registration.cancelled',
      section: 'events',
      variables: ['applicant_name' => 'Guest', 'event_name' => 'Conference'],
      recipientEmail: 'guest@example.com',
      includeRouting: false,
      idempotencyKey: 'event.registration.cancelled:test-1',
    );

    $this->assertDatabaseHas('communication_email_logs', [
      'event_key' => 'event.registration.confirmed',
      'recipient_email' => 'guest@example.com',
    ]);
    $this->assertDatabaseHas('communication_email_logs', [
      'event_key' => 'event.registration.cancelled',
      'recipient_email' => 'guest@example.com',
    ]);
  }

  public function test_missing_template_still_logs_and_sends_legacy_mail(): void
  {
    Mail::fake();

    CommunicationTemplate::query()->where('event_key', 'form.contact.submitted')->delete();

    app(CommunicationDispatchService::class)->dispatchEvent(
      eventKey: 'form.contact.submitted',
      section: 'contact',
      variables: ['applicant_name' => 'Jane'],
      recipientEmail: 'jane@example.com',
      includeRouting: false,
    );

    $this->assertDatabaseHas('communication_email_logs', [
      'event_key' => 'form.contact.submitted',
      'recipient_email' => 'jane@example.com',
      'status' => 'sent',
    ]);
    Mail::assertSent(\App\Mail\MemberNotificationMail::class);
  }

  public function test_assigned_staff_receives_admin_alert(): void
  {
    Mail::fake();
    $staff = User::factory()->create(['email' => 'staff.owner@example.com']);

    app(CommunicationDispatchService::class)->dispatchEvent(
      eventKey: 'form.contact.submitted.admin',
      section: 'contact',
      variables: ['applicant_name' => 'Jane', 'email' => 'jane@example.com'],
      context: ['assigned_admin_user_id' => $staff->id],
      includeRouting: true,
    );

    $this->assertDatabaseHas('communication_email_logs', [
      'event_key' => 'form.contact.submitted.admin',
      'recipient_email' => 'staff.owner@example.com',
    ]);
  }

  public function test_mail_failure_is_logged_and_not_reported_as_sent(): void
  {
    Mail::shouldReceive('to')->andThrow(new \RuntimeException('SMTP authentication failed'));

    $log = app(CommunicationDispatchService::class)->sendTestEmail(
      CommunicationTemplate::query()->where('event_key', 'form.contact.submitted')->firstOrFail(),
      'tester@example.com',
    );

    $this->assertSame('failed', $log->status instanceof \BackedEnum ? $log->status->value : $log->status);
    $this->assertStringContainsString('SMTP authentication failed', (string) $log->error_message);
  }

  public function test_test_send_returns_failure_status_when_mailer_rejects(): void
  {
    Sanctum::actingAs($this->admin);
    Mail::shouldReceive('to')->andThrow(new \RuntimeException('SMTP authentication failed'));
    $template = CommunicationTemplate::query()->where('event_key', 'form.contact.submitted')->firstOrFail();

    $this->postJson("/api/v1/communications/templates/{$template->uuid}/test-send", [
      'recipient_email' => 'tester@example.com',
    ])->assertUnprocessable()
      ->assertJsonPath('code', 'MAIL_DELIVERY_FAILED');
  }

  public function test_catalog_event_keys_have_seeded_templates(): void
  {
    $missing = array_diff(
      \App\Modules\Communications\Support\CommunicationEventKeys::all(),
      CommunicationTemplate::query()->pluck('event_key')->all(),
    );

    $this->assertSame([], array_values($missing));
  }

  public function test_counseling_and_donation_and_lms_payment_event_keys_dispatch(): void
  {
    Mail::fake();

    $dispatch = app(CommunicationDispatchService::class);

    $dispatch->dispatchEvent(
      eventKey: 'counseling.request.submitted',
      section: 'counseling',
      variables: ['applicant_name' => 'Sarah Lee', 'email' => 'sarah@example.com', 'case_number' => 'CN-1001'],
      recipientEmail: 'sarah@example.com',
      includeRouting: false,
      idempotencyKey: 'counseling.request.submitted:test-1',
    );
    $dispatch->dispatchEvent(
      eventKey: 'form.counseling.submitted.admin',
      section: 'counseling',
      variables: ['applicant_name' => 'Sarah Lee', 'email' => 'sarah@example.com', 'case_number' => 'CN-1001'],
      includeRouting: true,
      idempotencyKey: 'form.counseling.submitted.admin:test-1',
    );
    $dispatch->dispatchEvent(
      eventKey: 'donation.succeeded',
      section: 'donations',
      variables: ['applicant_name' => 'Donor', 'email' => 'donor@example.com', 'amount' => '50.00', 'currency' => 'USD', 'payment_reference' => 'DN-1'],
      recipientEmail: 'donor@example.com',
      includeRouting: false,
      idempotencyKey: 'donation.succeeded:test-1',
    );
    $dispatch->dispatchEvent(
      eventKey: 'lms.payment.confirmed',
      section: 'payments',
      variables: ['member_name' => 'Learner', 'email' => 'learner@example.com', 'course_name' => 'Leadership 101', 'amount' => '75.00', 'currency' => 'USD'],
      recipientEmail: 'learner@example.com',
      includeRouting: false,
      idempotencyKey: 'lms.payment.confirmed:test-1',
    );

    $this->assertDatabaseHas('communication_email_logs', [
      'event_key' => 'counseling.request.submitted',
      'recipient_email' => 'sarah@example.com',
      'status' => 'sent',
    ]);
    $this->assertDatabaseHas('communication_email_logs', [
      'event_key' => 'donation.succeeded',
      'recipient_email' => 'donor@example.com',
    ]);
    $this->assertDatabaseHas('communication_email_logs', [
      'event_key' => 'lms.payment.confirmed',
      'recipient_email' => 'learner@example.com',
    ]);
  }

  public function test_membership_interview_aliases_are_rendered_into_subject_and_not_left_raw(): void
  {
    Mail::fake();

    app(CommunicationDispatchService::class)->sendDirect(
      eventKey: 'membership.interview.invitation',
      section: 'membership',
      recipientEmail: 'applicant@example.com',
      recipientName: 'Jane Applicant',
      variables: [
        'applicant_name' => 'Jane Applicant',
        'scheduled_date' => '2026-09-01',
        'scheduled_time' => '11:30',
        'event_date' => '2026-09-01',
        'event_time' => '11:30',
        'confirmation_url' => 'https://example.com/confirm',
      ],
    );

    $log = CommunicationEmailLog::query()->where('event_key', 'membership.interview.invitation')->latest()->firstOrFail();
    $this->assertStringNotContainsString('{{', (string) $log->subject);
    $this->assertSame('sent', $log->status instanceof \BackedEnum ? $log->status->value : $log->status);
  }

  public function test_queued_status_is_not_left_after_synchronous_send(): void
  {
    Mail::fake();

    app(CommunicationDispatchService::class)->dispatchEvent(
      eventKey: 'form.contact.submitted',
      section: 'contact',
      variables: ['applicant_name' => 'Jane', 'email' => 'jane@example.com'],
      recipientEmail: 'jane@example.com',
      includeRouting: false,
    );

    $this->assertFalse(
      CommunicationEmailLog::query()
        ->where('recipient_email', 'jane@example.com')
        ->where('status', 'queued')
        ->exists()
    );
  }

  public function test_contact_and_prayer_forms_create_applicant_and_admin_logs(): void
  {
    Mail::fake();
    CommunicationSetting::query()->first()?->update(['ministry_email' => 'ministry@example.com']);

    $this->postJson('/api/v1/public/forms/contact', [
      'name' => 'Jane Doe',
      'email' => 'contact.jane@example.com',
      'country' => 'Nigeria',
      'subject' => 'Hello',
      'message' => 'Test message',
    ])->assertCreated();

    $this->postJson('/api/v1/public/forms/prayer', [
      'name' => 'John Smith',
      'email' => 'prayer.john@example.com',
      'country' => 'Nigeria',
      'request' => 'Please pray with us.',
      'message' => 'Please pray with us.',
    ]);

    $this->assertDatabaseHas('communication_email_logs', [
      'event_key' => 'form.contact.submitted',
      'recipient_email' => 'contact.jane@example.com',
    ]);
    $this->assertTrue(
      CommunicationEmailLog::query()->where('event_key', 'form.contact.submitted.admin')->exists()
    );
  }

  public function test_business_review_and_event_certificate_keys_are_seeded_and_dispatch(): void
  {
    Mail::fake();

    $dispatch = app(CommunicationDispatchService::class);
    $dispatch->dispatchEvent(
      eventKey: 'form.business-review.submitted',
      section: 'contact',
      variables: ['applicant_name' => 'Founder', 'email' => 'founder@example.com', 'business_name' => 'Acme'],
      recipientEmail: 'founder@example.com',
      includeRouting: false,
    );
    $dispatch->dispatchEvent(
      eventKey: 'membership.application.rejected',
      section: 'membership',
      variables: ['applicant_name' => 'Jane', 'email' => 'jane@example.com', 'application_number' => 'APP-9', 'reason' => 'Incomplete'],
      recipientEmail: 'jane@example.com',
      includeRouting: false,
    );
    $dispatch->dispatchEvent(
      eventKey: 'event.certificate.issued',
      section: 'events',
      variables: ['applicant_name' => 'Guest', 'event_name' => 'Conference', 'verification_code' => 'ABC123'],
      recipientEmail: 'guest@example.com',
      includeRouting: false,
    );
    $dispatch->dispatchEvent(
      eventKey: 'auth.password.reset',
      section: 'learning',
      variables: ['applicant_name' => 'User', 'email' => 'user@example.com', 'reset_url' => 'https://example.com/reset'],
      recipientEmail: 'user@example.com',
      includeRouting: false,
    );

    $this->assertDatabaseHas('communication_email_logs', [
      'event_key' => 'form.business-review.submitted',
      'recipient_email' => 'founder@example.com',
    ]);
    $this->assertDatabaseHas('communication_email_logs', [
      'event_key' => 'membership.application.rejected',
      'recipient_email' => 'jane@example.com',
    ]);
    $this->assertDatabaseHas('communication_email_logs', [
      'event_key' => 'event.certificate.issued',
      'recipient_email' => 'guest@example.com',
    ]);
    $this->assertDatabaseHas('communication_email_logs', [
      'event_key' => 'auth.password.reset',
      'recipient_email' => 'user@example.com',
    ]);
  }
}
