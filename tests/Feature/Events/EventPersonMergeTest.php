<?php

declare(strict_types=1);

namespace Tests\Feature\Events;

use App\Enums\MemberApprovalStatus;
use App\Enums\MemberStatus;
use App\Models\Member;
use App\Models\Permission;
use App\Models\Person;
use App\Models\User;
use App\Modules\Events\Enums\EventAuditEventType;
use App\Modules\Events\Enums\EventRegServiceStatus;
use App\Modules\Events\Enums\EventRegServiceType;
use App\Modules\Events\Enums\EventStatus;
use App\Modules\Events\Enums\EventVisibility;
use App\Modules\Events\Enums\PaymentStatus;
use App\Modules\Events\Enums\RegistrationAuditEventType;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Models\EventAuditLog;
use App\Modules\Events\Models\EventDayAttendance;
use App\Modules\Events\Models\EventRegistration;
use App\Modules\Events\Models\EventRegistrationAuditLog;
use App\Modules\Events\Models\EventRegistrationPayment;
use App\Modules\Events\Models\EventRegService;
use App\Modules\Events\Models\EventStaffAssignment;
use App\Modules\Events\Services\EventDayService;
use App\Services\Iam\AuthorizationService;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Iam\IamTestCase;

final class EventPersonMergeTest extends IamTestCase
{
  private function publishedEvent(string $title): Event
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

  private function registerGuest(Event $event, string $name, string $email, string $phone): EventRegistration
  {
    $this->postJson('/api/v1/public/events/registrations', [
      'event_id' => $event->uuid,
      'registrant' => ['name' => $name, 'email' => $email, 'phone' => $phone],
      'consent_accepted' => true,
    ])->assertSuccessful();

    return EventRegistration::query()->where('event_id', $event->id)->whereHas('person', fn ($q) => $q->where('email', strtolower($email)))->firstOrFail();
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
    ], $extra)));
    $user->permissions()->sync(Permission::query()->whereIn('slug', $slugs)->pluck('id'));
    app(AuthorizationService::class)->clearCacheForUser($user);

    return $user;
  }

  public function test_global_admin_can_preview_and_merge_across_events(): void
  {
    $eventA = $this->publishedEvent('Merge Summit');
    $eventB = $this->publishedEvent('Merge Workshop');
    $sourceReg = $this->registerGuest($eventA, 'Ada Source', 'ada.merge@example.com', '+447911200001');
    $targetReg = $this->registerGuest($eventB, 'Ada Target', 'ada.surviving@example.com', '+447911200002');
    $source = $sourceReg->person;
    $target = $targetReg->person;
    $source->update(['city' => 'Lagos', 'organization' => null, 'region' => 'South West']);
    $target->update(['city' => 'London', 'organization' => 'Marketplace', 'region' => null]);

    $preview = $this->postJson('/api/v1/events/persons/merge/preview', [
      'source_person_id' => $source->uuid,
      'target_person_id' => $target->uuid,
    ])->assertOk();
    $this->assertTrue($preview->json('data.can_merge'));
    $this->assertCount(1, $preview->json('data.reassign_registrations'));

    $this->postJson('/api/v1/events/persons/merge', [
      'source_person_id' => $source->uuid,
      'target_person_id' => $target->uuid,
      'confirm' => 'MERGE',
    ])->assertOk();

    $this->assertNotNull(Person::query()->find($target->id));
    $this->assertNull(Person::query()->find($source->id));
    $this->assertTrue(Person::withTrashed()->find($source->id)?->trashed());
    $this->assertSame(2, EventRegistration::query()->where('person_id', $target->id)->count());
    $this->assertSame(1, EventRegistration::query()->where('person_id', $target->id)->where('event_id', $eventA->id)->count());
    $this->assertSame(1, EventRegistration::query()->where('person_id', $target->id)->where('event_id', $eventB->id)->count());
    $this->assertSame('London', $target->fresh()->city);
    $this->assertSame('Marketplace', $target->fresh()->organization);
    $this->assertSame('South West', $target->fresh()->region);
    $this->assertSame(1, EventAuditLog::query()->where('event_type', EventAuditEventType::PersonMerged->value)->count());
    $this->assertSame(1, EventRegistrationAuditLog::query()->where('event_type', RegistrationAuditEventType::PersonMerged->value)->count());
  }

  public function test_event_staff_and_unauthenticated_cannot_merge(): void
  {
    $event = $this->publishedEvent('Staff Merge Block');
    $source = $this->registerGuest($event, 'Staff Source', 'staff.source@example.com', '+447911200010')->person;
    $target = Person::factory()->create(['email' => 'staff.target@example.com']);
    $staff = $this->staffUser();
    EventStaffAssignment::query()->create([
      'event_id' => $event->id,
      'user_id' => $staff->id,
      'staff_role' => 'staff',
      'is_active' => true,
    ]);

    Sanctum::actingAs($staff);
    $this->postJson('/api/v1/events/persons/merge/preview', [
      'source_person_id' => $source->uuid,
      'target_person_id' => $target->uuid,
    ])->assertForbidden();
    $this->postJson('/api/v1/events/persons/merge', [
      'source_person_id' => $source->uuid,
      'target_person_id' => $target->uuid,
      'confirm' => 'MERGE',
    ])->assertForbidden();

    $this->app['auth']->forgetGuards();
    $this->postJson('/api/v1/events/persons/merge', [
      'source_person_id' => $source->uuid,
      'target_person_id' => $target->uuid,
      'confirm' => 'MERGE',
    ])->assertUnauthorized();
  }

  public function test_same_event_collision_merges_attendance_services_and_payments(): void
  {
    $event = $this->publishedEvent('Collision Conference');
    $sourceReg = $this->registerGuest($event, 'Chi Source', 'chi.source@example.com', '+447911200020');
    $targetReg = $this->registerGuest($event, 'Chi Target', 'chi.target@example.com', '+447911200021');
    $day = $event->days->first();

    $this->postJson("/api/v1/events/registrations/{$sourceReg->uuid}/check-in", ['event_day_id' => $day->uuid])->assertCreated();
    EventRegService::query()->create([
      'registration_id' => $sourceReg->id,
      'type' => EventRegServiceType::Transport->value,
      'status' => EventRegServiceStatus::Confirmed->value,
      'details' => ['airport' => 'LHR'],
    ]);
    EventRegistrationPayment::query()->create([
      'registration_id' => $sourceReg->id,
      'event_id' => $event->id,
      'amount' => 25,
      'currency' => 'GBP',
      'status' => PaymentStatus::Paid,
    ]);

    $this->postJson('/api/v1/events/persons/merge', [
      'source_person_id' => $sourceReg->person->uuid,
      'target_person_id' => $targetReg->person->uuid,
      'confirm' => 'MERGE',
    ])->assertOk();

    $survivingId = $targetReg->person_id;
    $this->assertSame(1, EventRegistration::query()->where('event_id', $event->id)->where('person_id', $survivingId)->count());
    $keeper = EventRegistration::query()->where('event_id', $event->id)->where('person_id', $survivingId)->firstOrFail();
    $this->assertSame(1, EventDayAttendance::query()->where('registration_id', $keeper->id)->where('event_day_id', $day->id)->count());
    $this->assertTrue(EventDayAttendance::query()->where('registration_id', $keeper->id)->first()?->countsAsAttended());
    $this->assertSame(1, EventRegService::query()->where('registration_id', $keeper->id)->where('type', EventRegServiceType::Transport->value)->count());
    $this->assertSame(1, EventRegistrationPayment::query()->where('registration_id', $keeper->id)->count());
    $this->assertSame(1, EventRegistration::query()->where('event_id', $event->id)->count());
    $this->assertNotNull(EventRegistration::withTrashed()->where('event_id', $event->id)->whereNotNull('deleted_at')->first());
    $this->assertNull($keeper->deleted_at);
  }

  public function test_unsafe_collision_does_not_partially_merge(): void
  {
    $event = $this->publishedEvent('Unsafe Collision');
    $sourceReg = $this->registerGuest($event, 'Ben Source', 'ben.source@example.com', '+447911200030');
    $targetReg = $this->registerGuest($event, 'Ben Target', 'ben.target@example.com', '+447911200031');

    EventRegService::query()->create([
      'registration_id' => $sourceReg->id,
      'type' => EventRegServiceType::Transport->value,
      'status' => EventRegServiceStatus::Confirmed->value,
    ]);
    EventRegService::query()->create([
      'registration_id' => $targetReg->id,
      'type' => EventRegServiceType::Transport->value,
      'status' => EventRegServiceStatus::Confirmed->value,
    ]);

    $this->postJson('/api/v1/events/persons/merge', [
      'source_person_id' => $sourceReg->person->uuid,
      'target_person_id' => $targetReg->person->uuid,
      'confirm' => 'MERGE',
    ])->assertUnprocessable();

    $this->assertNotNull(Person::query()->find($sourceReg->person_id));
    $this->assertNotNull(Person::query()->find($targetReg->person_id));
    $this->assertSame($sourceReg->person_id, $sourceReg->fresh()->person_id);
    $this->assertSame($targetReg->person_id, $targetReg->fresh()->person_id);
    $this->assertSame(0, EventAuditLog::query()->where('event_type', EventAuditEventType::PersonMerged->value)->count());
  }

  public function test_member_and_user_conflicts_block_merge(): void
  {
    $source = Person::factory()->create(['email' => 'member.source@example.com']);
    $target = Person::factory()->create(['email' => 'member.target@example.com']);
    Member::factory()->create([
      'person_id' => $source->id,
      'email' => 'member.source@example.com',
      'status' => MemberStatus::Active->value,
      'approval_status' => MemberApprovalStatus::Approved->value,
    ]);
    Member::factory()->create([
      'person_id' => $target->id,
      'email' => 'member.target@example.com',
      'status' => MemberStatus::Active->value,
      'approval_status' => MemberApprovalStatus::Approved->value,
    ]);

    $this->postJson('/api/v1/events/persons/merge', [
      'source_person_id' => $source->uuid,
      'target_person_id' => $target->uuid,
      'confirm' => 'MERGE',
    ])->assertUnprocessable()
      ->assertJsonFragment(['Both people are linked to different member records. Member accounts cannot be merged.']);

    $userA = User::factory()->create();
    $userB = User::factory()->create();
    $personA = Person::factory()->create(['email' => 'user.a@example.com', 'user_id' => $userA->id]);
    $personB = Person::factory()->create(['email' => 'user.b@example.com', 'user_id' => $userB->id]);
    $this->postJson('/api/v1/events/persons/merge', [
      'source_person_id' => $personA->uuid,
      'target_person_id' => $personB->uuid,
      'confirm' => 'MERGE',
    ])->assertUnprocessable()
      ->assertJsonFragment(['Both people are linked to different user accounts. User/authentication identities cannot be merged.']);
  }

  public function test_repeated_merge_and_identity_reuse_remain_safe(): void
  {
    $eventA = $this->publishedEvent('Repeat Alpha');
    $eventB = $this->publishedEvent('Repeat Beta');
    $sourceReg = $this->registerGuest($eventA, 'Eve Source', 'eve.source@example.com', '+447911200040');
    $targetReg = $this->registerGuest($eventB, 'Eve Target', 'eve.target@example.com', '+447911200041');

    $this->postJson('/api/v1/events/persons/merge', [
      'source_person_id' => $sourceReg->person->uuid,
      'target_person_id' => $targetReg->person->uuid,
      'confirm' => 'MERGE',
    ])->assertOk();

    $this->postJson('/api/v1/events/persons/merge', [
      'source_person_id' => $sourceReg->person->uuid,
      'target_person_id' => $targetReg->person->uuid,
      'confirm' => 'MERGE',
    ])->assertNotFound();

    $this->postJson('/api/v1/public/events/registrations', [
      'event_id' => $eventA->uuid,
      'registrant' => ['name' => 'Eve Target', 'email' => 'eve.target@example.com', 'phone' => '+447911200041'],
      'consent_accepted' => true,
    ])->assertSuccessful();

    $this->assertSame(1, EventRegistration::query()->where('event_id', $eventA->id)->where('person_id', $targetReg->person_id)->count());

    $eventC = $this->publishedEvent('Repeat Gamma');
    $this->postJson('/api/v1/public/events/registrations', [
      'event_id' => $eventC->uuid,
      'registrant' => ['name' => 'Eve Source', 'email' => 'eve.source@example.com', 'phone' => '+447911200040'],
      'consent_accepted' => true,
    ])->assertSuccessful();

    $revived = Person::query()->whereRaw('LOWER(email) = ?', ['eve.source@example.com'])->first();
    $this->assertNotNull($revived);
    $this->assertNotSame($sourceReg->person_id, $revived->id);
    $this->assertTrue(Person::withTrashed()->find($sourceReg->person_id)?->trashed());
  }

  public function test_prefer_source_fields_do_not_overwrite_unselected_conflicts(): void
  {
    $eventA = $this->publishedEvent('Field Merge A');
    $eventB = $this->publishedEvent('Field Merge B');
    $source = $this->registerGuest($eventA, 'Fay Source', 'fay.source@example.com', '+447911200050')->person;
    $target = $this->registerGuest($eventB, 'Fay Target', 'fay.target@example.com', '+447911200051')->person;
    $source->update(['city' => 'Accra', 'organization' => 'Source Ministry']);
    $target->update(['city' => 'Nairobi', 'organization' => 'Target Ministry']);

    $this->postJson('/api/v1/events/persons/merge', [
      'source_person_id' => $source->uuid,
      'target_person_id' => $target->uuid,
      'prefer_source_fields' => ['city'],
      'confirm' => 'MERGE',
    ])->assertOk();

    $target->refresh();
    $this->assertSame('Accra', $target->city);
    $this->assertSame('Target Ministry', $target->organization);
  }

  public function test_source_user_and_member_transfer_when_target_has_none(): void
  {
    $user = User::factory()->create();
    $source = Person::factory()->create(['email' => 'transfer.source@example.com', 'user_id' => $user->id]);
    $target = Person::factory()->create(['email' => 'transfer.target@example.com', 'user_id' => null]);
    $member = Member::factory()->create([
      'person_id' => $source->id,
      'email' => 'transfer.source@example.com',
      'status' => MemberStatus::Active->value,
      'approval_status' => MemberApprovalStatus::Approved->value,
    ]);

    $this->postJson('/api/v1/events/persons/merge', [
      'source_person_id' => $source->uuid,
      'target_person_id' => $target->uuid,
      'confirm' => 'MERGE',
    ])->assertOk();

    $this->assertSame($user->id, $target->fresh()->user_id);
    $this->assertNull(Person::withTrashed()->find($source->id)?->user_id);
    $this->assertSame($target->id, $member->fresh()->person_id);
  }

  public function test_staff_scoping_still_blocks_other_events_after_merge_code(): void
  {
    $eventA = $this->publishedEvent('Scope After Merge A');
    $eventB = $this->publishedEvent('Scope After Merge B');
    $staff = $this->staffUser(['attendance.manage']);
    EventStaffAssignment::query()->create([
      'event_id' => $eventA->id,
      'user_id' => $staff->id,
      'staff_role' => 'staff',
      'is_active' => true,
    ]);
    Sanctum::actingAs($staff);
    $this->getJson("/api/v1/events/{$eventA->uuid}")->assertOk();
    $this->getJson("/api/v1/events/{$eventB->uuid}")->assertForbidden();
  }
}
