<?php

declare(strict_types=1);

namespace Tests\Feature\Events;

use App\Enums\MemberApprovalStatus;
use App\Enums\MemberStatus;
use App\Models\Member;
use App\Models\Permission;
use App\Models\Person;
use App\Modules\Cms\Models\CmsCountry;
use App\Modules\Events\Enums\EventStatus;
use App\Modules\Events\Enums\EventVisibility;
use App\Modules\Events\Enums\RegistrationAuditEventType;
use App\Modules\Events\Enums\RegistrationStatus;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Models\EventRegistration;
use App\Modules\Events\Models\EventRegistrationAuditLog;
use Database\Seeders\CommunicationSeeder;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Iam\IamTestCase;

final class EventPersonIdentityTest extends IamTestCase
{
    private function publishedEvent(string $slug): Event
    {
        return Event::query()->create([
            'title' => 'Identity '.$slug,
            'slug' => $slug,
            'starts_at' => now()->addWeeks(2),
            'ends_at' => now()->addWeeks(2)->addDays(3),
            'visibility' => EventVisibility::Public,
            'status' => EventStatus::Published,
            'published_at' => now(),
            'capacity' => 80,
        ]);
    }

    private function portalMember(string $email, string $phone = '+14155550100', ?int $countryId = null): array
    {
        $user = $this->memberUser();
        $permission = Permission::query()->where('slug', 'member.portal')->firstOrFail();
        $user->permissions()->syncWithoutDetaching([$permission->id]);

        $member = Member::factory()->create([
            'user_id' => $user->id,
            'email' => $email,
            'phone' => $phone,
            'country_id' => $countryId,
            'status' => MemberStatus::Active->value,
            'approval_status' => MemberApprovalStatus::Approved->value,
        ]);

        return ['user' => $user, 'member' => $member->fresh()];
    }

    public function test_new_public_registration_creates_one_person_and_one_registration(): void
    {
        $event = $this->publishedEvent('identity-new-person');

        $this->postJson('/api/v1/public/events/registrations', [
            'event_id' => $event->uuid,
            'registrant' => [
                'name' => 'Amara Okonkwo',
                'email' => 'amara.okonkwo@example.com',
                'phone' => '+2348012345678',
            ],
            'profile' => [
                'country' => 'Nigeria',
                'state_region' => 'Lagos',
                'city' => 'Ikeja',
            ],
            'consent_accepted' => true,
        ])->assertCreated();

        $this->assertSame(1, Person::query()->where('email', 'amara.okonkwo@example.com')->count());
        $this->assertSame(1, EventRegistration::query()->where('event_id', $event->id)->count());

        $person = Person::query()->where('email', 'amara.okonkwo@example.com')->first();
        $this->assertNotNull($person);
        $this->assertMatchesRegularExpression('/^PERSON-\d{6}$/', (string) $person->person_no);
        $this->assertSame('2348012345678', $person->phone_digits);

        $registration = EventRegistration::query()->where('event_id', $event->id)->first();
        $this->assertSame($person->id, $registration->person_id);
        $this->assertNotNull($registration->registration_number);
        $this->assertTrue(
            EventRegistrationAuditLog::query()
                ->where('registration_id', $registration->id)
                ->where('event_type', RegistrationAuditEventType::IdentityResolved->value)
                ->exists()
        );
    }

    public function test_existing_person_is_reused_across_events_without_duplicate_person(): void
    {
        $july = $this->publishedEvent('identity-july');
        $august = $this->publishedEvent('identity-august');

        $this->postJson('/api/v1/public/events/registrations', [
            'event_id' => $july->uuid,
            'registrant' => [
                'name' => 'John Doe',
                'email' => 'john.doe@example.com',
                'phone' => '+447911123456',
            ],
            'profile' => ['country' => 'United Kingdom', 'state_region' => 'Greater London', 'city' => 'London'],
            'consent_accepted' => true,
        ])->assertCreated();

        $this->postJson('/api/v1/public/events/registrations', [
            'event_id' => $august->uuid,
            'registrant' => [
                'name' => 'John Doe',
                'email' => 'john.doe@example.com',
                'phone' => '+447911123456',
            ],
            'profile' => ['country' => 'United Kingdom', 'city' => 'Manchester'],
            'consent_accepted' => true,
        ])->assertCreated();

        $this->assertSame(1, Person::query()->where('email', 'john.doe@example.com')->count());
        $person = Person::query()->where('email', 'john.doe@example.com')->first();
        $this->assertSame(2, EventRegistration::query()->where('person_id', $person->id)->count());
        $this->assertNotSame(
            EventRegistration::query()->where('event_id', $july->id)->value('registration_number'),
            EventRegistration::query()->where('event_id', $august->id)->value('registration_number'),
        );
    }

    public function test_same_person_cannot_create_a_second_registration_for_the_same_event(): void
    {
        $event = $this->publishedEvent('identity-duplicate-event');

        $this->postJson('/api/v1/public/events/registrations', [
            'event_id' => $event->uuid,
            'registrant' => ['name' => 'Jane Doe', 'email' => 'jane.doe@example.com', 'phone' => '+254700111222'],
            'consent_accepted' => true,
        ])->assertCreated();

        $this->postJson('/api/v1/public/events/registrations', [
            'event_id' => $event->uuid,
            'registrant' => ['name' => 'Jane Doe', 'email' => 'jane.doe@example.com', 'phone' => '+254700111222'],
            'consent_accepted' => true,
        ])->assertOk();

        $this->assertSame(1, EventRegistration::query()->where('event_id', $event->id)->count());
        $this->assertSame(1, Person::query()->where('email', 'jane.doe@example.com')->count());
    }

    public function test_existing_member_maps_to_person_and_sees_own_events_only(): void
    {
        $kenya = CmsCountry::query()->create([
            'name' => 'Kenya',
            'slug' => 'kenya-identity-test',
            'code' => 'KE',
            'is_active' => true,
            'sort_order' => 2,
        ]);
        $uk = CmsCountry::query()->create([
            'name' => 'United Kingdom',
            'slug' => 'uk-identity-test',
            'code' => 'GB',
            'is_active' => true,
            'sort_order' => 3,
        ]);

        $owner = $this->portalMember('member.owner@example.com', '+254722000111', $kenya->id);
        $other = $this->portalMember('member.other@example.com', '+447700900123', $uk->id);

        $event = $this->publishedEvent('identity-member-portal');
        $otherEvent = $this->publishedEvent('identity-other-portal');

        Sanctum::actingAs($owner['user']);
        $created = $this->postJson('/api/v1/public/events/registrations', [
            'event_id' => $event->uuid,
            'registrant' => [
                'name' => $owner['member']->fullName(),
                'email' => $owner['member']->email,
                'phone' => $owner['member']->phone,
            ],
            'consent_accepted' => true,
        ])->assertCreated();

        $registrationId = $created->json('data.registration.id');
        $this->assertSame($owner['member']->id, EventRegistration::query()->where('uuid', $registrationId)->value('member_id'));
        $this->assertSame($owner['member']->person_id, EventRegistration::query()->where('uuid', $registrationId)->value('person_id'));

        Sanctum::actingAs($other['user']);
        $this->postJson('/api/v1/public/events/registrations', [
            'event_id' => $otherEvent->uuid,
            'registrant' => [
                'name' => $other['member']->fullName(),
                'email' => $other['member']->email,
                'phone' => $other['member']->phone,
            ],
            'consent_accepted' => true,
        ])->assertCreated();
        $otherRegistrationId = EventRegistration::query()->where('event_id', $otherEvent->id)->value('uuid');

        Sanctum::actingAs($owner['user']);
        $list = $this->getJson('/api/v1/member-portal/events')->assertOk();
        $ids = collect($list->json('data.registrations'))->pluck('id')->all();
        $this->assertContains($registrationId, $ids);
        $this->assertNotContains($otherRegistrationId, $ids);

        $detail = $this->getJson('/api/v1/member-portal/events/'.$registrationId)->assertOk();
        $this->assertSame($registrationId, $detail->json('data.registration.id'));
        $this->assertNotEmpty($detail->json('data.registration.person.name'));
        $this->assertArrayHasKey('submitted', $detail->json('data.registration'));
        $this->assertArrayHasKey('accommodation', $detail->json('data.registration'));

        $this->getJson('/api/v1/member-portal/events/'.$otherRegistrationId)->assertNotFound();
    }

    public function test_staff_can_search_and_reuse_a_non_member_participant(): void
    {
        $first = $this->publishedEvent('identity-staff-first');
        $second = $this->publishedEvent('identity-staff-second');

        $this->postJson('/api/v1/public/events/registrations', [
            'event_id' => $first->uuid,
            'registrant' => [
                'name' => 'Sofia Alvarez',
                'email' => 'sofia.alvarez@example.com',
                'phone' => '+525512345678',
            ],
            'profile' => ['country' => 'Mexico', 'state_region' => 'CDMX', 'city' => 'Mexico City'],
            'consent_accepted' => true,
        ])->assertCreated();

        $person = Person::query()->where('email', 'sofia.alvarez@example.com')->first();
        $this->assertNotNull($person);
        $this->assertNull($person->member);

        $search = $this->getJson('/api/v1/events/registrations/search?q=sofia.alvarez@example.com')->assertOk();
        $personHits = $search->json('data.persons');
        $this->assertNotEmpty($personHits);
        $this->assertSame($person->uuid, $personHits[0]['person_id'] ?? $personHits[0]['id']);

        $this->postJson('/api/v1/events/registrations', [
            'event_id' => $second->uuid,
            'person_id' => $person->uuid,
            'consent_accepted' => true,
        ])->assertCreated();

        $this->assertSame(1, Person::query()->where('email', 'sofia.alvarez@example.com')->count());
        $this->assertSame(2, EventRegistration::query()->where('person_id', $person->id)->count());

        $profile = $this->getJson('/api/v1/events/persons/'.$person->uuid)->assertOk();
        $this->assertCount(2, $profile->json('data.registrations'));
    }

    public function test_public_request_cannot_attach_another_persons_identity(): void
    {
        $event = $this->publishedEvent('identity-public-hijack');
        $victim = Person::factory()->create([
            'email' => 'victim@example.com',
            'display_name' => 'Victim Person',
        ]);

        $this->postJson('/api/v1/public/events/registrations', [
            'event_id' => $event->uuid,
            'person_id' => $victim->uuid,
            'registrant' => [
                'name' => 'Attacker',
                'email' => 'attacker@example.com',
                'phone' => '+16175550123',
            ],
            'consent_accepted' => true,
        ])->assertCreated();

        $registration = EventRegistration::query()->where('event_id', $event->id)->first();
        $this->assertNotSame($victim->id, $registration->person_id);
        $this->assertSame('attacker@example.com', Person::query()->find($registration->person_id)?->email);
    }

    public function test_event_specific_profile_does_not_overwrite_person_canonical_fields(): void
    {
        $july = $this->publishedEvent('identity-profile-july');
        $november = $this->publishedEvent('identity-profile-nov');

        $this->postJson('/api/v1/public/events/registrations', [
            'event_id' => $july->uuid,
            'registrant' => ['name' => 'Priya Sharma', 'email' => 'priya.sharma@example.com', 'phone' => '+919876543210'],
            'profile' => ['occupation' => 'Engineer', 'organization' => 'July Ministry'],
            'consent_accepted' => true,
        ])->assertCreated();

        $person = Person::query()->where('email', 'priya.sharma@example.com')->first();
        $originalOrg = $person->organization;

        $this->postJson('/api/v1/public/events/registrations', [
            'event_id' => $november->uuid,
            'registrant' => ['name' => 'Priya Sharma', 'email' => 'priya.sharma@example.com', 'phone' => '+919876543210'],
            'profile' => ['occupation' => 'Pastor', 'organization' => 'November Outreach'],
            'consent_accepted' => true,
        ])->assertCreated();

        $person->refresh();
        $this->assertSame($originalOrg, $person->organization);

        $novemberReg = EventRegistration::query()->where('event_id', $november->id)->first();
        $this->assertSame('Pastor', $novemberReg->metadata['profile']['occupation'] ?? null);
        $julyReg = EventRegistration::query()->where('event_id', $july->id)->first();
        $this->assertSame('Engineer', $julyReg->metadata['profile']['occupation'] ?? null);
    }

    public function test_registration_numbers_are_unique_and_staff_search_distinguishes_same_name(): void
    {
        $event = $this->publishedEvent('identity-same-name');

        $this->postJson('/api/v1/public/events/registrations', [
            'event_id' => $event->uuid,
            'registrant' => ['name' => 'Alex Kim', 'email' => 'alex.one@example.com', 'phone' => '+821011110001'],
            'consent_accepted' => true,
        ])->assertCreated();

        $second = $this->publishedEvent('identity-same-name-b');
        $this->postJson('/api/v1/public/events/registrations', [
            'event_id' => $second->uuid,
            'registrant' => ['name' => 'Alex Kim', 'email' => 'alex.two@example.com', 'phone' => '+821011110002'],
            'consent_accepted' => true,
        ])->assertCreated();

        $this->assertSame(2, Person::query()->where('display_name', 'Alex Kim')->count());
        $numbers = EventRegistration::query()->pluck('registration_number')->all();
        $this->assertCount(count($numbers), array_unique($numbers));

        $search = $this->getJson('/api/v1/events/registrations/search?q=Alex%20Kim')->assertOk();
        $this->assertGreaterThanOrEqual(2, count($search->json('data.persons')));
    }

    public function test_public_registration_dispatches_existing_communication_event(): void
    {
        $this->seed(CommunicationSeeder::class);
        Mail::fake();

        $event = $this->publishedEvent('identity-notify');

        $this->postJson('/api/v1/public/events/registrations', [
            'event_id' => $event->uuid,
            'registrant' => [
                'name' => 'Chioma Nwosu',
                'email' => 'chioma.nwosu@example.com',
                'phone' => '+2348090001111',
            ],
            'consent_accepted' => true,
        ])->assertCreated();

        $this->assertDatabaseHas('communication_email_logs', [
            'event_key' => 'event.registration.confirmed',
            'recipient_email' => 'chioma.nwosu@example.com',
        ]);
    }
}
