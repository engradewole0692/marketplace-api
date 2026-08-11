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
}
