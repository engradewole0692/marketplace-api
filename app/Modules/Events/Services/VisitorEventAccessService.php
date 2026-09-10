<?php

declare(strict_types=1);

namespace App\Modules\Events\Services;

use App\Contracts\ServiceContract;
use App\Models\Person;
use App\Models\User;
use App\Modules\Events\Models\EventRegistration;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Visitor workspace event access using the existing learner.portal account.
 * Email + surname is an additional claim step to link a Person to the visitor user.
 */
final class VisitorEventAccessService implements ServiceContract
{
    public function resolvePerson(User $user, bool $createIfMissing = false): ?Person
    {
        $person = Person::query()->where('user_id', $user->id)->first();
        if ($person !== null) {
            return $person;
        }

        $email = strtolower(trim((string) $user->email));
        if ($email !== '') {
            $person = Person::query()->whereRaw('LOWER(email) = ?', [$email])->first();
            if ($person !== null) {
                if ($person->user_id === null) {
                    $person->user_id = $user->id;
                    $person->save();
                }

                return $person;
            }
        }

        if (! $createIfMissing) {
            return null;
        }

        return Person::query()->create([
            'person_no' => Person::nextTemporaryNumber(),
            'user_id' => $user->id,
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'display_name' => $user->display_name ?: $user->name,
            'email' => $email !== '' ? $email : null,
        ]);
    }

    public function claimWithSurname(User $user, string $email, string $surname): Person
    {
        $userEmail = strtolower(trim((string) $user->email));
        $provided = strtolower(trim($email));
        if ($userEmail === '' || $provided !== $userEmail) {
            throw ValidationException::withMessages([
                'email' => ['Use the same email address as your visitor account.'],
            ]);
        }

        $person = Person::query()->whereRaw('LOWER(email) = ?', [$provided])->first();
        if ($person === null) {
            throw ValidationException::withMessages([
                'email' => ['No participant record matches that email.'],
            ]);
        }

        $normalizedSurname = $this->normalizeName($surname);
        $personSurname = $this->normalizeName((string) $person->last_name);
        $display = $this->normalizeName((string) $person->display_name);
        $matches = $personSurname !== '' && $personSurname === $normalizedSurname;
        if (! $matches && $display !== '') {
            $parts = preg_split('/\s+/', $display) ?: [];
            $last = $this->normalizeName((string) end($parts));
            $matches = $last === $normalizedSurname;
        }

        if (! $matches) {
            throw ValidationException::withMessages([
                'surname' => ['Surname does not match the participant record. Name-only matching is not accepted.'],
            ]);
        }

        if ($person->user_id !== null && (int) $person->user_id !== (int) $user->id) {
            throw ValidationException::withMessages([
                'email' => ['This participant identity is already linked to another account.'],
            ]);
        }

        $person->user_id = $user->id;
        $person->save();

        return $person;
    }

    /**
     * @return Collection<int, EventRegistration>
     */
    public function registrationsFor(User $user): Collection
    {
        $person = $this->resolvePerson($user);
        if ($person === null) {
            return new Collection();
        }

        return EventRegistration::query()
            ->where('person_id', $person->id)
            ->with(['event.venue', 'event.country', 'services', 'payments', 'dayAttendances.day', 'person.member', 'person.country'])
            ->latest('submitted_at')
            ->get();
    }

    public function ownedRegistration(User $user, string $registrationUuid): EventRegistration
    {
        $person = $this->resolvePerson($user);
        if ($person === null) {
            throw new NotFoundHttpException();
        }

        $registration = EventRegistration::query()
            ->where('person_id', $person->id)
            ->where('uuid', $registrationUuid)
            ->first();
        if ($registration === null) {
            throw new NotFoundHttpException();
        }

        return $registration;
    }

    private function normalizeName(string $value): string
    {
        return strtolower(trim(preg_replace('/\s+/', ' ', $value) ?? $value));
    }
}
