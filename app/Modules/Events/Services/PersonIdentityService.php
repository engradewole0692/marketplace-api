<?php

declare(strict_types=1);

namespace App\Modules\Events\Services;

use App\Contracts\ServiceContract;
use App\Models\Member;
use App\Models\Person;
use App\Models\User;
use App\Modules\Cms\Models\CmsCountry;
use App\Modules\Events\Models\EventRegistration;
use App\Modules\Events\Support\PhoneNumberNormalizer;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * Canonical person identity for event participants.
 * Members and non-member guests share one Person; event answers stay on registrations.
 */
final class PersonIdentityService implements ServiceContract
{
    /**
     * @param  array<string, mixed>  $data
     * @return array{person: Person, member: ?Member, created: bool, match_reasons: list<string>}
     */
    public function resolve(array $data, ?User $actor = null, bool $staffContext = false): array
    {
        $registrant = is_array($data['registrant'] ?? null) ? $data['registrant'] : [];
        $profile = is_array($data['profile'] ?? null) ? $data['profile'] : [];
        $contact = $this->contactFromPayload($registrant, $profile, $actor);

        $explicitPerson = $staffContext ? $this->personFromId($data['person_id'] ?? null) : null;
        if ($explicitPerson !== null) {
            $this->fillBlankCanonicalFields($explicitPerson, $contact);
            $member = $explicitPerson->member ?? Member::query()->where('person_id', $explicitPerson->id)->first();

            return [
                'person' => $explicitPerson->fresh(['member', 'country']) ?? $explicitPerson,
                'member' => $member,
                'created' => false,
                'match_reasons' => ['staff_selected'],
            ];
        }

        $member = $this->memberFromId($staffContext ? ($data['member_id'] ?? null) : null);
        if (! $staffContext && $actor !== null) {
            $actor->loadMissing('member');
            $member ??= $actor->member;
        }

        if ($member !== null) {
            $person = $this->ensureForMember($member);

            return [
                'person' => $person,
                'member' => $member,
                'created' => false,
                'match_reasons' => ['member'],
            ];
        }

        $matches = $this->findCandidates($contact, $staffContext);
        if ($matches['person'] !== null) {
            $person = $matches['person'];
            $this->fillBlankCanonicalFields($person, $contact);
            if ($actor !== null && $person->user_id === null && $this->actorOwnsContact($actor, $contact)) {
                $person->user_id = $actor->id;
                $person->save();
            }

            return [
                'person' => $person->fresh(['member', 'country']) ?? $person,
                'member' => $person->member,
                'created' => false,
                'match_reasons' => $matches['reasons'],
            ];
        }

        $person = $this->createPerson($contact, $actor);

        return [
            'person' => $person,
            'member' => null,
            'created' => true,
            'match_reasons' => ['created'],
        ];
    }

    public function ensureForMember(Member $member): Person
    {
        if ($member->person_id) {
            $person = Person::query()->find($member->person_id);
            if ($person !== null) {
                $this->syncMemberOntoPerson($member, $person);

                return $person;
            }
        }

        $existing = $this->findByEmail($member->email)
            ?? $this->findUniqueByPhone($member->phone)
            ?? ($member->user_id ? Person::query()->where('user_id', $member->user_id)->first() : null);

        if ($existing !== null) {
            $this->attachMember($existing, $member);

            return $existing;
        }

        $person = Person::query()->create([
            'person_no' => Person::nextTemporaryNumber(),
            'user_id' => $member->user_id,
            'first_name' => $member->first_name,
            'last_name' => $member->last_name,
            'display_name' => $member->fullName(),
            'email' => $this->normalizeEmail($member->email),
            'phone' => $member->phone,
            'country_id' => $member->country_id,
            'region' => $member->state,
            'city' => $member->city,
            'organization' => $member->organization,
        ]);

        $this->attachMember($person, $member);

        return $person;
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function paginate(array $filters = []): LengthAwarePaginator
    {
        $query = Person::query()->with(['member', 'country'])->orderBy('display_name');

        if (! empty($filters['search']) || ! empty($filters['q'])) {
            $term = trim((string) ($filters['search'] ?? $filters['q']));
            $this->applySearch($query, $term);
        }

        return $query->paginate(min(max((int) ($filters['per_page'] ?? 25), 1), 100));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function search(string $query, int $limit = 12): array
    {
        $term = trim($query);
        if (strlen($term) < 2) {
            return [];
        }

        $like = '%'.$term.'%';
        $digits = Person::digits($term);
        $email = $this->normalizeEmail($term);

        $persons = Person::query()
            ->with(['member', 'country'])
            ->where(function ($builder) use ($like, $digits, $email, $term): void {
                $builder->where('display_name', 'like', $like)
                    ->orWhere('first_name', 'like', $like)
                    ->orWhere('last_name', 'like', $like)
                    ->orWhere('email', 'like', $like)
                    ->orWhere('phone', 'like', $like)
                    ->orWhere('person_no', 'like', $like)
                    ->orWhereHas('member', function ($memberQuery) use ($like): void {
                        $memberQuery->where('membership_number', 'like', $like)
                            ->orWhere('email', 'like', $like)
                            ->orWhere('phone', 'like', $like)
                            ->orWhere('first_name', 'like', $like)
                            ->orWhere('last_name', 'like', $like)
                            ->orWhere('display_name', 'like', $like);
                    });

                if ($digits !== null) {
                    $builder->orWhere('phone_digits', 'like', '%'.$digits.'%');
                }
                if ($email !== null) {
                    $builder->orWhereRaw('LOWER(email) = ?', [$email]);
                }
            })
            ->orderBy('display_name')
            ->limit($limit)
            ->get();

        $fromRegistrations = EventRegistration::query()
            ->with(['person.member', 'person.country', 'member', 'event'])
            ->where(function ($builder) use ($like): void {
                $builder->where('registration_number', 'like', $like)
                    ->orWhere('guest_email', 'like', $like)
                    ->orWhere('guest_phone', 'like', $like)
                    ->orWhere('guest_name', 'like', $like);
            })
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();

        $byId = [];
        foreach ($persons as $person) {
            $byId[$person->id] = $this->searchHit($person, $this->matchReasons($person, $term, $email, $digits));
        }

        foreach ($fromRegistrations as $registration) {
            $person = $registration->person;
            if ($person === null && $registration->member_id) {
                $person = $this->ensureForMember($registration->member ?? Member::query()->findOrFail($registration->member_id));
            }
            if ($person === null) {
                continue;
            }
            if (! isset($byId[$person->id])) {
                $byId[$person->id] = $this->searchHit($person, ['registration']);
            }
            $byId[$person->id]['registrations'][] = [
                'id' => $registration->uuid,
                'registration_number' => $registration->registration_number,
                'event_id' => $registration->event?->uuid,
                'event_title' => $registration->event?->title,
                'status' => $registration->status instanceof \BackedEnum ? $registration->status->value : $registration->status,
            ];
        }

        return array_values($byId);
    }

    public function history(Person $person): Collection
    {
        $query = EventRegistration::query()
            ->where('person_id', $person->id)
            ->with(['event.venue', 'event.country', 'services', 'payments', 'checkIns', 'attendanceHistories', 'plannedSessions', 'sessionAttendances.session'])
            ->latest('submitted_at');
        app(EventAuthorizationService::class)->restrictEventOwnedQuery($query, auth()->user());

        return $query->get();
    }

    /**
     * @param  array{name: ?string, first_name: ?string, last_name: ?string, email: ?string, phone: ?string, country: ?string, region: ?string, city: ?string, organization: ?string}  $contact
     * @return array{person: ?Person, reasons: list<string>}
     */
    private function findCandidates(array $contact, bool $staffContext): array
    {
        $reasons = [];

        if ($contact['email'] !== null) {
            $person = $this->findByEmail($contact['email']);
            if ($person !== null) {
                return ['person' => $person, 'reasons' => ['email']];
            }
        }

        if ($contact['phone'] !== null) {
            $person = $this->findUniqueByPhone($contact['phone']);
            if ($person !== null) {
                if ($staffContext || $contact['email'] === null) {
                    return ['person' => $person, 'reasons' => ['phone']];
                }
            }
        }

        return ['person' => null, 'reasons' => $reasons];
    }

    public function findByEmail(?string $email): ?Person
    {
        $email = $this->normalizeEmail($email);
        if ($email === null) {
            return null;
        }

        return Person::query()->with(['member', 'country'])->whereRaw('LOWER(email) = ?', [$email])->first();
    }

    public function findUniqueByPhone(?string $phone): ?Person
    {
        $digits = Person::digits($phone);
        if ($digits === null) {
            return null;
        }

        $matches = Person::query()->with(['member', 'country'])->where('phone_digits', $digits)->limit(2)->get();

        return $matches->count() === 1 ? $matches->first() : null;
    }

    /**
     * @param  array{name: ?string, first_name: ?string, last_name: ?string, email: ?string, phone: ?string, country: ?string, region: ?string, city: ?string, organization: ?string}  $contact
     */
    private function createPerson(array $contact, ?User $actor): Person
    {
        [$first, $last] = $this->splitName($contact['name'], $contact['first_name'], $contact['last_name']);

        return Person::query()->create([
            'person_no' => Person::nextTemporaryNumber(),
            'user_id' => $this->actorOwnsContact($actor, $contact) ? $actor?->id : null,
            'first_name' => $first,
            'last_name' => $last,
            'display_name' => $contact['name'] ?: trim(($first ?? '').' '.($last ?? '')) ?: ($contact['email'] ?: 'Participant'),
            'email' => $contact['email'],
            'phone' => $contact['phone'],
            'phone_country_code' => $contact['phone_country_code'] ?? null,
            'country_id' => $this->resolveCountryId($contact['country']),
            'region' => $contact['region'],
            'city' => $contact['city'],
            'organization' => $contact['organization'],
        ]);
    }

    /**
     * @param  array{name: ?string, first_name: ?string, last_name: ?string, email: ?string, phone: ?string, country: ?string, region: ?string, city: ?string, organization: ?string}  $contact
     */
    private function fillBlankCanonicalFields(Person $person, array $contact): void
    {
        $dirty = false;

        if ($person->email === null && $contact['email'] !== null) {
            $person->email = $contact['email'];
            $dirty = true;
        }
        if ($person->phone === null && $contact['phone'] !== null) {
            $person->phone = $contact['phone'];
            if (! empty($contact['phone_country_code'])) {
                $person->phone_country_code = $contact['phone_country_code'];
            }
            $dirty = true;
        }
        if ($person->country_id === null) {
            $countryId = $this->resolveCountryId($contact['country']);
            if ($countryId !== null) {
                $person->country_id = $countryId;
                $dirty = true;
            }
        }
        if ($person->region === null && $contact['region'] !== null) {
            $person->region = $contact['region'];
            $dirty = true;
        }
        if ($person->city === null && $contact['city'] !== null) {
            $person->city = $contact['city'];
            $dirty = true;
        }
        if ($person->organization === null && $contact['organization'] !== null) {
            $person->organization = $contact['organization'];
            $dirty = true;
        }

        if ($dirty) {
            $person->save();
        }
    }

    private function syncMemberOntoPerson(Member $member, Person $person): void
    {
        $dirty = false;
        if ($person->user_id === null && $member->user_id) {
            $person->user_id = $member->user_id;
            $dirty = true;
        }
        if ($dirty) {
            $person->save();
        }
        if ($member->person_id !== $person->id) {
            $member->person_id = $person->id;
            $member->saveQuietly();
        }
    }

    private function attachMember(Person $person, Member $member): void
    {
        if ($person->user_id === null && $member->user_id) {
            $person->user_id = $member->user_id;
            $person->save();
        }
        if ($member->person_id !== $person->id) {
            $member->person_id = $person->id;
            $member->saveQuietly();
        }
    }

    /**
     * @param  array{email: ?string, phone: ?string, name: ?string, first_name: ?string, last_name: ?string, country: ?string, region: ?string, city: ?string, organization: ?string}  $contact
     */
    private function actorOwnsContact(?User $actor, array $contact): bool
    {
        if ($actor === null) {
            return false;
        }
        $actor->loadMissing('member');
        if ($actor->member !== null) {
            return true;
        }
        $actorEmail = $this->normalizeEmail($actor->email);

        return $actorEmail !== null && $contact['email'] !== null && $actorEmail === $contact['email'];
    }

    /**
     * @param  array<string, mixed>  $registrant
     * @param  array<string, mixed>  $profile
     * @return array{name: ?string, first_name: ?string, last_name: ?string, email: ?string, phone: ?string, country: ?string, region: ?string, city: ?string, organization: ?string}
     */
    private function contactFromPayload(array $registrant, array $profile, ?User $actor): array
    {
        $first = $this->stringOrNull($registrant['first_name'] ?? $profile['first_name'] ?? null);
        $last = $this->stringOrNull($registrant['last_name'] ?? $profile['last_name'] ?? null);
        $name = $this->stringOrNull($registrant['name'] ?? $registrant['full_name'] ?? null);
        if ($name === null && ($first !== null || $last !== null)) {
            $name = trim(($first ?? '').' '.($last ?? '')) ?: null;
        }
        if ($name === null && $actor !== null) {
            $name = $this->stringOrNull($actor->display_name ?: $actor->name);
        }

        $email = $this->normalizeEmail($registrant['email'] ?? $profile['email'] ?? $actor?->email);
        $countryCode = $this->stringOrNull(
            $registrant['phone_country_code'] ?? $profile['phone_country_code'] ?? null,
        );
        $normalized = PhoneNumberNormalizer::normalize(
            $this->stringOrNull($registrant['phone'] ?? $profile['phone'] ?? null),
            $countryCode,
        );

        return [
            'name' => $name,
            'first_name' => $first,
            'last_name' => $last,
            'email' => $email,
            'phone' => $normalized['phone'],
            'phone_country_code' => $normalized['country_code'],
            'country' => $this->stringOrNull($profile['country'] ?? $registrant['country'] ?? null),
            'region' => $this->stringOrNull($profile['state_region'] ?? $profile['state'] ?? $profile['region'] ?? $registrant['state_region'] ?? null),
            'city' => $this->stringOrNull($profile['city'] ?? $registrant['city'] ?? null),
            'organization' => $this->stringOrNull($profile['organization'] ?? $profile['ministry'] ?? $registrant['organization'] ?? null),
        ];
    }

    private function personFromId(mixed $id): ?Person
    {
        if ($id === null || $id === '') {
            return null;
        }

        if (is_numeric($id)) {
            return Person::query()->with(['member', 'country'])->find((int) $id);
        }

        return Person::query()->with(['member', 'country'])->where('uuid', (string) $id)->first();
    }

    private function memberFromId(mixed $id): ?Member
    {
        if ($id === null || $id === '') {
            return null;
        }

        if (is_numeric($id)) {
            return Member::query()->find((int) $id);
        }

        return Member::query()->where('uuid', (string) $id)->first();
    }

    private function resolveCountryId(?string $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $country = CmsCountry::query()
            ->where('uuid', $value)
            ->orWhere('slug', $value)
            ->orWhere('code', $value)
            ->orWhereRaw('LOWER(name) = ?', [strtolower($value)])
            ->first();

        return $country?->id;
    }

    /**
     * @return array{0: ?string, 1: ?string}
     */
    private function splitName(?string $name, ?string $first, ?string $last): array
    {
        if ($first !== null || $last !== null) {
            return [$first, $last];
        }
        if ($name === null || $name === '') {
            return [null, null];
        }
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        $head = array_shift($parts);

        return [$head !== '' ? $head : null, $parts === [] ? null : implode(' ', $parts)];
    }

    private function normalizeEmail(mixed $email): ?string
    {
        if (! is_string($email)) {
            return null;
        }
        $email = strtolower(trim($email));

        return $email === '' ? null : $email;
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * @param  list<string>  $reasons
     * @return array<string, mixed>
     */
    private function searchHit(Person $person, array $reasons): array
    {
        return [
            'id' => $person->uuid,
            'person_id' => $person->uuid,
            'person_no' => $person->person_no,
            'name' => $person->fullName(),
            'email' => $person->email,
            'phone' => $person->phone,
            'country' => $person->country?->name,
            'region' => $person->region,
            'city' => $person->city,
            'is_member' => $person->member !== null,
            'member_id' => $person->member?->uuid,
            'match_reasons' => array_values(array_unique($reasons)),
            'event_count' => $person->eventRegistrations()->count(),
            'registrations' => [],
        ];
    }

    /**
     * @return list<string>
     */
    private function matchReasons(Person $person, string $term, ?string $email, ?string $digits): array
    {
        $reasons = [];
        $lower = strtolower($term);
        if ($email !== null && strtolower((string) $person->email) === $email) {
            $reasons[] = 'email';
        }
        if ($digits !== null && $person->phone_digits === $digits) {
            $reasons[] = 'phone';
        }
        if (str_contains(strtolower($person->fullName()), $lower)) {
            $reasons[] = 'name';
        }
        if (str_contains(strtolower((string) $person->person_no), $lower)) {
            $reasons[] = 'person_no';
        }
        if ($person->member && str_contains(strtolower((string) $person->member->membership_number), $lower)) {
            $reasons[] = 'membership';
        }

        return $reasons === [] ? ['search'] : $reasons;
    }

    private function applySearch($query, string $term): void
    {
        $like = '%'.$term.'%';
        $digits = Person::digits($term);
        $query->where(function ($builder) use ($like, $digits): void {
            $builder->where('display_name', 'like', $like)
                ->orWhere('first_name', 'like', $like)
                ->orWhere('last_name', 'like', $like)
                ->orWhere('email', 'like', $like)
                ->orWhere('phone', 'like', $like)
                ->orWhere('person_no', 'like', $like)
                ->orWhereHas('member', function ($memberQuery) use ($like): void {
                    $memberQuery->where('membership_number', 'like', $like)
                        ->orWhere('email', 'like', $like)
                        ->orWhere('first_name', 'like', $like)
                        ->orWhere('last_name', 'like', $like);
                });
            if ($digits !== null) {
                $builder->orWhere('phone_digits', 'like', '%'.$digits.'%');
            }
        });
    }
}
