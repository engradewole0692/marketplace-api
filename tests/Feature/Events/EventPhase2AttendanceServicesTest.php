<?php

declare(strict_types=1);

namespace Tests\Feature\Events;

use App\Enums\MemberApprovalStatus;
use App\Enums\MemberStatus;
use App\Models\Member;
use App\Models\Permission;
use App\Models\Person;
use App\Models\User;
use App\Modules\Events\Enums\EventStatus;
use App\Modules\Events\Enums\EventVisibility;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Models\EventAccommodationAllocation;
use App\Modules\Events\Models\EventDayAttendance;
use App\Modules\Events\Models\EventRegistration;
use App\Modules\Events\Services\EventDayService;
use Database\Seeders\CommunicationSeeder;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Iam\IamTestCase;

final class EventPhase2AttendanceServicesTest extends IamTestCase
{
    private function multiDayEvent(): Event
    {
        $event = Event::query()->create([
            'title' => 'November Conference',
            'slug' => 'november-conference-'.uniqid(),
            'starts_at' => now()->startOfDay(),
            'ends_at' => now()->startOfDay()->addDays(2)->endOfDay(),
            'timezone' => 'UTC',
            'attendance_mode' => 'daily',
            'check_in_enabled' => true,
            'visibility' => EventVisibility::Public,
            'status' => EventStatus::Published,
            'published_at' => now(),
            'capacity' => 200,
        ]);
        app(EventDayService::class)->syncFromEvent($event);

        return $event->fresh(['days']);
    }

    private function oneDayEvent(): Event
    {
        $event = Event::query()->create([
            'title' => 'Saturday Gathering',
            'slug' => 'saturday-gathering-'.uniqid(),
            'starts_at' => now()->startOfDay()->addHours(9),
            'ends_at' => now()->startOfDay()->addHours(17),
            'timezone' => 'UTC',
            'attendance_mode' => 'single',
            'check_in_enabled' => true,
            'visibility' => EventVisibility::Public,
            'status' => EventStatus::Published,
            'published_at' => now(),
            'capacity' => 80,
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

    public function test_three_day_attendance_counts_are_independent(): void
    {
        $event = $this->multiDayEvent();
        $this->assertCount(3, $event->days);

        $john = $this->registerGuest($event, 'John Doe', 'john.phase2@example.com', '+447911100001');
        $jane = $this->registerGuest($event, 'Jane Doe', 'jane.phase2@example.com', '+447911100002');
        $peter = $this->registerGuest($event, 'Peter Smith', 'peter.phase2@example.com', '+447911100003');
        $mary = $this->registerGuest($event, 'Mary Absent', 'mary.phase2@example.com', '+447911100004');

        $days = $event->days()->orderBy('day_index')->get();

        foreach ($days as $day) {
            $this->postJson("/api/v1/events/registrations/{$john->uuid}/check-in", ['event_day_id' => $day->uuid])->assertCreated();
            $this->postJson("/api/v1/events/registrations/{$john->uuid}/check-out", ['event_day_id' => $day->uuid])->assertOk();
        }

        $this->postJson("/api/v1/events/registrations/{$jane->uuid}/check-in", ['event_day_id' => $days[0]->uuid])->assertCreated();
        $this->postJson("/api/v1/events/registrations/{$jane->uuid}/check-in", ['event_day_id' => $days[2]->uuid])->assertCreated();

        $this->postJson("/api/v1/events/registrations/{$peter->uuid}/check-in", ['event_day_id' => $days[1]->uuid])->assertCreated();

        $report = $this->getJson("/api/v1/events/{$event->uuid}/attendance-report")->assertOk();
        $rows = collect($report->json('data.rows'))->keyBy('registration_id');

        $this->assertSame('3/3', $rows[$john->uuid]['attendance']);
        $this->assertSame('2/3', $rows[$jane->uuid]['attendance']);
        $this->assertSame('1/3', $rows[$peter->uuid]['attendance']);
        $this->assertSame('0/3', $rows[$mary->uuid]['attendance']);
        $this->assertSame(1, $report->json('data.summary.attended_all_days'));
        $this->assertSame(1, $report->json('data.summary.attended_zero'));

        $this->assertSame(1, EventRegistration::query()->where('person_id', $john->person_id)->count());
    }

    public function test_one_day_event_is_one_of_one(): void
    {
        $event = $this->oneDayEvent();
        $this->assertCount(1, $event->days);
        $sarah = $this->registerGuest($event, 'Sarah One', 'sarah.phase2@example.com', '+16175550111');
        $this->postJson("/api/v1/events/registrations/{$sarah->uuid}/check-in", [])->assertCreated();
        $this->postJson("/api/v1/events/registrations/{$sarah->uuid}/check-in", [])->assertUnprocessable();

        $report = $this->getJson("/api/v1/events/{$event->uuid}/attendance-report")->assertOk();
        $this->assertSame('1/1', $report->json('data.rows.0.attendance'));
    }

    public function test_qr_check_in_is_per_day_and_rejects_wrong_event(): void
    {
        $event = $this->multiDayEvent();
        $other = $this->oneDayEvent();
        $guest = $this->registerGuest($event, 'QR Guest', 'qr.phase2@example.com', '+254700111000');
        $issue = $this->postJson("/api/v1/events/registrations/{$guest->uuid}/check-in-token")->assertCreated();
        $token = $issue->json('data.token');
        $days = $event->days()->orderBy('day_index')->get();

        $this->postJson('/api/v1/events/check-in/scan', [
            'token' => $token,
            'event_id' => $event->uuid,
            'event_day_id' => $days[0]->uuid,
        ])->assertCreated()->assertJsonPath('data.participant.name', 'QR Guest');

        $this->postJson('/api/v1/events/check-in/scan', [
            'token' => $token,
            'event_id' => $event->uuid,
            'event_day_id' => $days[1]->uuid,
        ])->assertCreated();

        $this->postJson('/api/v1/events/check-in/scan', [
            'token' => $token,
            'event_id' => $event->uuid,
            'event_day_id' => $days[1]->uuid,
        ])->assertUnprocessable();

        $this->postJson('/api/v1/events/check-out/scan', [
            'token' => $token,
            'event_id' => $event->uuid,
            'event_day_id' => $days[0]->uuid,
        ])->assertOk();

        $this->postJson('/api/v1/events/check-in/scan', [
            'token' => $token,
            'event_id' => $other->uuid,
        ])->assertUnprocessable();

        $this->postJson('/api/v1/events/check-in/scan', ['token' => 'INVALIDTOKEN'])->assertStatus(422);
    }

    public function test_member_and_visitor_are_classified_and_isolated(): void
    {
        $event = $this->oneDayEvent();
        $permission = Permission::query()->where('slug', 'member.portal')->firstOrFail();
        $learner = Permission::query()->where('slug', 'learner.portal')->firstOrFail();

        $memberUser = $this->memberUser();
        $memberUser->permissions()->syncWithoutDetaching([$permission->id]);
        $member = Member::factory()->create([
            'user_id' => $memberUser->id,
            'email' => 'approved.member.phase2@example.com',
            'phone' => '+254722111222',
            'status' => MemberStatus::Active->value,
            'approval_status' => MemberApprovalStatus::Approved->value,
        ]);

        Sanctum::actingAs($memberUser);
        $this->postJson('/api/v1/public/events/registrations', [
            'event_id' => $event->uuid,
            'registrant' => ['name' => $member->fullName(), 'email' => $member->email, 'phone' => $member->phone],
            'consent_accepted' => true,
        ])->assertCreated();

        $visitorUser = User::factory()->create(['email' => 'visitor.phase2@example.com', 'last_name' => 'Alvarez']);
        $visitorUser->permissions()->syncWithoutDetaching([$learner->id]);
        Sanctum::actingAs($this->admin);
        $this->registerGuest($event, 'Sofia Alvarez', 'visitor.phase2@example.com', '+525512340099');

        Sanctum::actingAs($memberUser);
        $memberList = $this->getJson('/api/v1/member-portal/events')->assertOk();
        $this->assertCount(1, $memberList->json('data.registrations'));
        $memberRegId = $memberList->json('data.registrations.0.id');
        $detail = $this->getJson('/api/v1/member-portal/events/'.$memberRegId)->assertOk();
        $this->assertSame('Approved Member', $detail->json('data.registration.attendance_summary.membership.label'));

        Sanctum::actingAs($visitorUser);
        $this->postJson('/api/v1/learner/events/claim', [
            'email' => 'visitor.phase2@example.com',
            'surname' => 'Alvarez',
        ])->assertOk();
        $visitorList = $this->getJson('/api/v1/learner/events')->assertOk();
        $this->assertCount(1, $visitorList->json('data.registrations'));
        $this->assertSame('Visitor', $visitorList->json('data.registrations.0.membership.label'));
        $visitorRegId = $visitorList->json('data.registrations.0.id');
        $this->getJson('/api/v1/learner/events/'.$memberRegId)->assertNotFound();

        Sanctum::actingAs($memberUser);
        $this->getJson('/api/v1/member-portal/events/'.$visitorRegId)->assertNotFound();
    }

    public function test_accommodation_inventory_pairing_and_notification(): void
    {
        $this->seed(CommunicationSeeder::class);
        Mail::fake();
        $event = $this->oneDayEvent();
        $a = $this->registerGuest($event, 'Room One', 'room.one@example.com', '+14155550101');
        $b = $this->registerGuest($event, 'Room Two', 'room.two@example.com', '+14155550102');

        $option = $this->postJson("/api/v1/events/{$event->uuid}/accommodation-options", [
            'name' => 'Deluxe Shared Room',
            'location' => 'Campus Lodge',
            'occupancy_type' => 'shared',
            'capacity' => 2,
            'unit_count' => 1,
            'price' => 40,
            'currency' => 'USD',
        ])->assertCreated()->json('data.option');

        $this->assertSame(2, $option['total_spaces']);

        $pairing = $this->postJson("/api/v1/events/registrations/{$a->uuid}/accommodation/pairing", [
            'registration_ids' => [$b->uuid],
            'option_id' => $option['id'],
        ])->assertCreated();
        $this->assertSame('pending_confirmation', $pairing->json('data.pairing.status'));

        $this->postJson('/api/v1/events/accommodation-pairings/'.$pairing->json('data.pairing.uuid').'/respond', [
            'registration_id' => $b->uuid,
            'accept' => true,
        ])->assertOk()->assertJsonPath('data.pairing.status', 'confirmed');

        $this->postJson("/api/v1/events/registrations/{$a->uuid}/accommodation/allocate", [
            'option_id' => $option['id'],
            'pairing_id' => $pairing->json('data.pairing.uuid'),
        ])->assertCreated();
        $this->postJson("/api/v1/events/registrations/{$b->uuid}/accommodation/allocate", [
            'option_id' => $option['id'],
            'pairing_id' => $pairing->json('data.pairing.uuid'),
        ])->assertCreated();

        $overflow = $this->registerGuest($event, 'Room Three', 'room.three@example.com', '+14155550103');
        $this->postJson("/api/v1/events/registrations/{$overflow->uuid}/accommodation/allocate", [
            'option_id' => $option['id'],
        ])->assertUnprocessable();

        $allocation = EventAccommodationAllocation::query()->where('registration_id', $a->id)->firstOrFail();
        $this->postJson("/api/v1/events/accommodation-allocations/{$allocation->uuid}/confirm")->assertOk();

        $this->assertDatabaseHas('communication_email_logs', [
            'event_key' => 'event.accommodation.confirmed',
            'recipient_email' => 'room.one@example.com',
        ]);
    }

    public function test_transport_confirmation_notifies_participant(): void
    {
        $this->seed(CommunicationSeeder::class);
        Mail::fake();
        $event = $this->oneDayEvent();
        $guest = $this->registerGuest($event, 'Ride Guest', 'ride.phase2@example.com', '+2348091112222');

        $this->postJson("/api/v1/events/registrations/{$guest->uuid}/services", [
            'type' => 'transport',
            'status' => 'confirmed',
            'details' => [
                'pickup' => 'Airport',
                'destination' => 'Venue',
                'vehicle' => 'Van 3',
                'driver' => 'Samuel',
                'contact' => '+2348000000000',
            ],
        ])->assertOk();

        $this->assertDatabaseHas('communication_email_logs', [
            'event_key' => 'event.transport.confirmed',
            'recipient_email' => 'ride.phase2@example.com',
        ]);
    }
}
