<?php

declare(strict_types=1);

namespace Tests\Feature\Events;

use App\Models\Permission;
use App\Models\User;
use App\Modules\Events\Enums\EventAuditEventType;
use App\Modules\Events\Enums\EventStatus;
use App\Modules\Events\Enums\EventVisibility;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Models\EventAuditLog;
use App\Modules\Events\Models\EventRegistration;
use App\Modules\Events\Models\EventStaffAssignment;
use App\Modules\Events\Services\EventDayService;
use App\Services\Iam\AuthorizationService;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Iam\IamTestCase;

final class EventStaffScopingTest extends IamTestCase
{
  private function createEvent(string $title): Event
  {
    $event = Event::query()->create([
      'title' => $title,
      'slug' => strtolower(str_replace(' ', '-', $title)).'-'.uniqid(),
      'starts_at' => now()->startOfDay()->addHours(9),
      'ends_at' => now()->startOfDay()->addHours(17),
      'timezone' => 'UTC',
      'attendance_mode' => 'single',
      'check_in_enabled' => true,
      'visibility' => EventVisibility::Public,
      'status' => EventStatus::Published,
      'published_at' => now(),
      'capacity' => 100,
    ]);
    app(EventDayService::class)->syncFromEvent($event);

    return $event->fresh(['days']);
  }

  /**
   * @param  list<string>  $extra
   */
  private function staffUser(array $extra = []): User
  {
    $user = User::factory()->create();
    $slugs = array_values(array_unique(array_merge([
      'events.staff',
      'events.view',
      'registrations.view',
      'registrations.manage',
      'attendance.manage',
      'event_payments.manage',
      'exports.manage',
      'reports.view',
    ], $extra)));
    $user->permissions()->sync(
      Permission::query()->whereIn('slug', $slugs)->pluck('id'),
    );
    app(AuthorizationService::class)->clearCacheForUser($user);

    return $user;
  }

  private function assign(Event $event, User $user, bool $active = true): EventStaffAssignment
  {
    return EventStaffAssignment::query()->create([
      'event_id' => $event->id,
      'user_id' => $user->id,
      'staff_role' => 'staff',
      'is_active' => $active,
      'created_by_user_id' => $this->admin->id,
    ]);
  }

  private function registerGuest(Event $event, string $email): EventRegistration
  {
    $this->postJson('/api/v1/public/events/registrations', [
      'event_id' => $event->uuid,
      'registrant' => ['name' => 'Guest '.strtok($email, '@'), 'email' => $email, 'phone' => '+447900'.random_int(100000, 999999)],
      'consent_accepted' => true,
    ])->assertSuccessful();

    return EventRegistration::query()->where('event_id', $event->id)->whereHas('person', fn ($q) => $q->where('email', strtolower($email)))->firstOrFail();
  }

  public function test_global_event_admin_can_access_multiple_events_without_assignment(): void
  {
    $eventA = $this->createEvent('Global Alpha');
    $eventB = $this->createEvent('Global Beta');

    $this->getJson("/api/v1/events/{$eventA->uuid}")->assertOk();
    $this->getJson("/api/v1/events/{$eventB->uuid}")->assertOk();

    $list = $this->getJson('/api/v1/events')->assertOk();
    $ids = collect($list->json('data.data'))->pluck('id');
    $this->assertTrue($ids->contains($eventA->uuid));
    $this->assertTrue($ids->contains($eventB->uuid));
  }

  public function test_assigned_staff_can_access_their_event_but_not_another(): void
  {
    $eventA = $this->createEvent('Staff Alpha');
    $eventB = $this->createEvent('Staff Beta');
    $staff = $this->staffUser();
    $this->assign($eventA, $staff);

    Sanctum::actingAs($staff);

    $this->getJson("/api/v1/events/{$eventA->uuid}")->assertOk();
    $this->getJson("/api/v1/events/{$eventB->uuid}")->assertForbidden();

    $list = $this->getJson('/api/v1/events')->assertOk();
    $ids = collect($list->json('data.data'))->pluck('id');
    $this->assertTrue($ids->contains($eventA->uuid));
    $this->assertFalse($ids->contains($eventB->uuid));
  }

  public function test_unassigned_staff_cannot_access_event(): void
  {
    $event = $this->createEvent('Unassigned Event');
    $staff = $this->staffUser();
    Sanctum::actingAs($staff);

    $this->getJson("/api/v1/events/{$event->uuid}")->assertForbidden();
  }

  public function test_deactivating_assignment_removes_access(): void
  {
    $event = $this->createEvent('Deactivate Access');
    $staff = $this->staffUser();
    $assignment = $this->assign($event, $staff);

    Sanctum::actingAs($staff);
    $this->getJson("/api/v1/events/{$event->uuid}")->assertOk();

    Sanctum::actingAs($this->admin);
    $this->deleteJson("/api/v1/events/staff/{$assignment->uuid}")->assertOk();
    $this->assertFalse($assignment->fresh()->is_active);
    $this->assertDatabaseHas('event_audit_logs', [
      'event_id' => $event->id,
      'event_type' => EventAuditEventType::StaffDeactivated->value,
    ]);

    Sanctum::actingAs($staff);
    $this->getJson("/api/v1/events/{$event->uuid}")->assertForbidden();
  }

  public function test_staff_cannot_access_another_events_child_resources(): void
  {
    $eventA = $this->createEvent('Ops Alpha');
    $eventB = $this->createEvent('Ops Beta');
    $staff = $this->staffUser();
    $this->assign($eventA, $staff);

    Sanctum::actingAs($this->admin);
    $regA = $this->registerGuest($eventA, 'alpha.staffscope@example.com');
    $regB = $this->registerGuest($eventB, 'beta.staffscope@example.com');

    Sanctum::actingAs($staff);

    $this->getJson("/api/v1/events/registrations/{$regA->uuid}")->assertOk();
    $this->getJson("/api/v1/events/registrations/{$regB->uuid}")->assertForbidden();

    $this->postJson("/api/v1/events/registrations/{$regB->uuid}/check-in", [])->assertForbidden();
    $this->postJson("/api/v1/events/registrations/{$regB->uuid}/services", [
      'type' => 'transport',
      'status' => 'requested',
    ])->assertForbidden();
    $this->postJson("/api/v1/events/registrations/{$regB->uuid}/accommodation/allocate", [
      'option_id' => 'missing',
    ])->assertForbidden();

    $this->getJson("/api/v1/events/{$eventB->uuid}/attendance-report")->assertForbidden();
    $this->getJson("/api/v1/events/{$eventB->uuid}/ops-dashboard")->assertForbidden();

    $this->postJson('/api/v1/events/exports', [
      'event_id' => $eventB->uuid,
      'export_type' => 'registrations',
      'format' => 'csv',
    ])->assertForbidden();

    $this->postJson('/api/v1/events/reports', [
      'event_id' => $eventB->uuid,
      'report_type' => 'event_summary',
    ])->assertForbidden();
  }

  public function test_staff_cannot_bypass_scope_by_submitting_another_event_id(): void
  {
    $eventA = $this->createEvent('Submit Alpha');
    $eventB = $this->createEvent('Submit Beta');
    $staff = $this->staffUser();
    $this->assign($eventA, $staff);

    Sanctum::actingAs($this->admin);
    $this->registerGuest($eventB, 'bypass.staffscope@example.com');

    Sanctum::actingAs($staff);
    $this->postJson('/api/v1/events/registrations', [
      'event_id' => $eventB->id,
      'registrant' => ['name' => 'Bypass', 'email' => 'bypass2.staffscope@example.com', 'phone' => '+447911109999'],
      'consent_accepted' => true,
    ])->assertForbidden();

    $this->getJson('/api/v1/events/registrations/search?q=bypass.staffscope&event_id='.$eventB->uuid)
      ->assertForbidden();
  }

  public function test_qr_scan_from_unassigned_event_is_forbidden(): void
  {
    $eventA = $this->createEvent('QR Alpha');
    $eventB = $this->createEvent('QR Beta');
    $staff = $this->staffUser();
    $this->assign($eventA, $staff);

    Sanctum::actingAs($this->admin);
    $guest = $this->registerGuest($eventB, 'qr.staffscope@example.com');
    $token = $this->postJson("/api/v1/events/registrations/{$guest->uuid}/check-in-token")->assertCreated()->json('data.token');

    Sanctum::actingAs($staff);
    $this->postJson('/api/v1/events/check-in/scan', [
      'token' => $token,
      'event_id' => $eventB->uuid,
    ])->assertForbidden();
  }

  public function test_admin_can_assign_staff_and_duplicate_assignment_reactivates(): void
  {
    $event = $this->createEvent('Assign Staff');
    $staff = $this->staffUser();

    $created = $this->postJson("/api/v1/events/{$event->uuid}/staff", [
      'user_id' => $staff->uuid,
      'staff_role' => 'coordinator',
    ])->assertCreated();

    $assignmentId = $created->json('data.assignment.id');
    $this->assertDatabaseHas('event_staff_assignments', [
      'event_id' => $event->id,
      'user_id' => $staff->id,
      'is_active' => 1,
    ]);
    $this->assertSame(1, EventAuditLog::query()->where('event_id', $event->id)->where('event_type', EventAuditEventType::StaffAssigned->value)->count());

    $this->deleteJson("/api/v1/events/staff/{$assignmentId}")->assertOk();

    $this->postJson("/api/v1/events/{$event->uuid}/staff", [
      'user_id' => $staff->uuid,
      'staff_role' => 'staff',
    ])->assertCreated();

    $this->assertSame(1, EventStaffAssignment::query()->where('event_id', $event->id)->where('user_id', $staff->id)->count());
    $this->assertTrue((bool) EventStaffAssignment::query()->where('event_id', $event->id)->where('user_id', $staff->id)->value('is_active'));
  }

  public function test_staff_cannot_assign_staff_or_create_events(): void
  {
    $event = $this->createEvent('Staff Cannot Assign');
    $staff = $this->staffUser();
    $other = $this->staffUser();
    $this->assign($event, $staff);

    Sanctum::actingAs($staff);
    $this->postJson("/api/v1/events/{$event->uuid}/staff", [
      'user_id' => $other->uuid,
    ])->assertForbidden();

    $this->postJson('/api/v1/events', [
      'title' => 'Should Not Create',
      'starts_at' => now()->toIso8601String(),
    ])->assertForbidden();
  }

  public function test_existing_member_and_visitor_routes_are_unaffected(): void
  {
    $event = $this->createEvent('Public Still Visible');

    $this->getJson('/api/v1/public/events')->assertOk();
    $this->getJson("/api/v1/public/events/{$event->uuid}")->assertOk();
  }
}
