<?php

declare(strict_types=1);

namespace App\Modules\Communications\Services;

use App\Models\Member;
use App\Models\Person;
use App\Models\User;
use App\Modules\BusinessReview\Models\BusinessReview;
use App\Modules\Cms\Enums\FormSubmissionType;
use App\Modules\Cms\Models\CmsFormSubmission;
use App\Modules\Counselling\Models\CounsellingCase;
use App\Modules\Events\Support\PhoneNumberNormalizer;
use App\Modules\Lms\Models\Enrollment;
use Illuminate\Support\Collection;

/**
 * Platform-wide recipient resolution for the existing Communications composer.
 * Does not create Person records. Module filters only use fields the module stores.
 */
final class RecipientAudienceService
{
    /**
     * @return list<array<string, mixed>>
     */
    public function modules(): array
    {
        return [
            [
                'id' => 'general',
                'label' => 'General platform',
                'filters' => [
                    ['key' => 'audience', 'label' => 'Audience', 'type' => 'select', 'options' => ['all', 'members', 'visitors', 'staff', 'admins']],
                    ['key' => 'country_id', 'label' => 'Country ID', 'type' => 'number'],
                    ['key' => 'role_slug', 'label' => 'Role slug', 'type' => 'text'],
                    ['key' => 'ministry_id', 'label' => 'Ministry ID', 'type' => 'number'],
                ],
            ],
            [
                'id' => 'events',
                'label' => 'Events',
                'filters' => [
                    ['key' => 'event_id', 'label' => 'Event', 'type' => 'text'],
                    ['key' => 'event_session_id', 'label' => 'Session', 'type' => 'text'],
                    ['key' => 'registration_status', 'label' => 'Registration status', 'type' => 'text'],
                    ['key' => 'gender', 'label' => 'Gender', 'type' => 'text'],
                    ['key' => 'category', 'label' => 'Participant category', 'type' => 'text'],
                    ['key' => 'audience', 'label' => 'Member / visitor', 'type' => 'select', 'options' => ['members', 'visitors']],
                    ['key' => 'country', 'label' => 'Country', 'type' => 'text'],
                    ['key' => 'state_region', 'label' => 'State / province', 'type' => 'text'],
                    ['key' => 'city', 'label' => 'City', 'type' => 'text'],
                    ['key' => 'accommodation', 'label' => 'Accommodation requested', 'type' => 'boolean'],
                    ['key' => 'occupancy_type', 'label' => 'Private / shared', 'type' => 'select', 'options' => ['private', 'shared']],
                    ['key' => 'transport', 'label' => 'Transport requested', 'type' => 'boolean'],
                    ['key' => 'payment', 'label' => 'Payment status', 'type' => 'select', 'options' => ['paid', 'pending']],
                    ['key' => 'attendance', 'label' => 'Attendance', 'type' => 'select', 'options' => ['attended', 'absent', 'checked_in', 'checked_out']],
                    ['key' => 'seat_counting', 'label' => 'Seat-counting', 'type' => 'select', 'options' => ['yes', 'no']],
                ],
            ],
            [
                'id' => 'lms',
                'label' => 'LMS / Learning',
                'filters' => [
                    ['key' => 'school_id', 'label' => 'School ID', 'type' => 'number'],
                    ['key' => 'course_id', 'label' => 'Course ID', 'type' => 'number'],
                    ['key' => 'program_module_id', 'label' => 'Module ID', 'type' => 'number'],
                    ['key' => 'lesson_id', 'label' => 'Lesson ID', 'type' => 'number'],
                    ['key' => 'enrollment_status', 'label' => 'Enrollment status', 'type' => 'select', 'options' => ['active', 'completed', 'cancelled', 'expired', 'pending_payment', 'locked']],
                    ['key' => 'assignment_status', 'label' => 'Assignment status', 'type' => 'text'],
                    ['key' => 'learner_type', 'label' => 'Member / public learner', 'type' => 'select', 'options' => ['member', 'public']],
                ],
            ],
            [
                'id' => 'counseling',
                'label' => 'Counseling',
                'filters' => [
                    ['key' => 'status', 'label' => 'Case status', 'type' => 'text'],
                    ['key' => 'counsellor_id', 'label' => 'Counsellor ID', 'type' => 'number'],
                    ['key' => 'category_id', 'label' => 'Category ID', 'type' => 'number'],
                    ['key' => 'assigned', 'label' => 'Assigned', 'type' => 'select', 'options' => ['yes', 'no']],
                    ['key' => 'payment', 'label' => 'Payment status', 'type' => 'select', 'options' => ['paid', 'pending']],
                    ['key' => 'client_type', 'label' => 'Member / visitor', 'type' => 'text'],
                ],
            ],
            [
                'id' => 'prayer',
                'label' => 'Prayer',
                'filters' => [
                    ['key' => 'status', 'label' => 'Request status', 'type' => 'text'],
                    ['key' => 'category', 'label' => 'Prayer category', 'type' => 'text'],
                ],
            ],
            [
                'id' => 'membership',
                'label' => 'Membership',
                'filters' => [
                    ['key' => 'status', 'label' => 'Membership status', 'type' => 'text'],
                    ['key' => 'approval_status', 'label' => 'Approval status', 'type' => 'text'],
                    ['key' => 'ministry_id', 'label' => 'Ministry ID', 'type' => 'number'],
                    ['key' => 'interview_status', 'label' => 'Interview status', 'type' => 'text'],
                ],
            ],
            [
                'id' => 'ministries',
                'label' => 'Ministries',
                'filters' => [
                    ['key' => 'ministry_id', 'label' => 'Ministry ID', 'type' => 'number'],
                    ['key' => 'status', 'label' => 'Membership status', 'type' => 'text'],
                ],
            ],
            [
                'id' => 'business_review',
                'label' => 'Business Review',
                'filters' => [
                    ['key' => 'status', 'label' => 'Review status', 'type' => 'text'],
                    ['key' => 'country', 'label' => 'Country', 'type' => 'text'],
                ],
            ],
            [
                'id' => 'forms',
                'label' => 'Forms',
                'filters' => [
                    ['key' => 'form_type', 'label' => 'Form type', 'type' => 'text'],
                    ['key' => 'status', 'label' => 'Submission status', 'type' => 'text'],
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array{email:?string, phone:?string, name:?string, user_id:?int, person_id:?int}>
     */
    public function collect(array $filters): Collection
    {
        $module = strtolower(trim((string) ($filters['module'] ?? '')));
        if ($module === '' && (! empty($filters['event_id']) || ! empty($filters['event_session_id']))) {
            $module = 'events';
        }
        if ($module === '' && ! empty($filters['course_id'])) {
            $module = 'lms';
        }

        $rows = match ($module) {
            'events' => app(BulkEmailService::class)->collectEventRecipients($filters),
            'lms' => $this->lmsRecipients($filters),
            'counseling', 'counselling' => $this->counselingRecipients($filters),
            'prayer' => $this->formRecipients($filters, FormSubmissionType::Prayer->value),
            'membership' => $this->membershipRecipients($filters),
            'ministries' => $this->membershipRecipients($filters),
            'business_review' => $this->businessReviewRecipients($filters),
            'forms' => $this->formRecipients($filters, isset($filters['form_type']) ? (string) $filters['form_type'] : null),
            default => app(BulkEmailService::class)->collectUserRecipients($filters),
        };

        return $this->dedupe($rows);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function preview(array $filters): array
    {
        $channel = strtolower((string) ($filters['channel'] ?? 'email'));
        $rows = $this->collect($filters);
        $valid = [];
        $invalid = [];
        foreach ($rows as $row) {
            if ($this->isValidForChannel($row, $channel)) {
                $valid[] = $row;
            } else {
                $invalid[] = $row;
            }
        }

        $sample = array_slice(array_map(fn (array $row): array => [
            'name' => $row['name'],
            'email' => $this->maskEmail($row['email']),
            'phone' => $this->maskPhone($row['phone']),
        ], $valid), 0, 12);

        return [
            'channel' => $channel,
            'module' => $filters['module'] ?? null,
            'total' => count($rows),
            'valid_count' => count($valid),
            'invalid_count' => count($invalid),
            'deduplicated_count' => count($rows),
            'sample' => $sample,
        ];
    }

    /**
     * Search existing Person and User contact records. Name-only queries return candidates; they are never auto-selected.
     *
     * @return list<array<string, mixed>>
     */
    public function search(string $query, int $limit = 20): array
    {
        $q = trim($query);
        if (strlen($q) < 2) {
            return [];
        }

        $like = '%'.$q.'%';
        $digits = preg_replace('/\D+/', '', $q) ?? '';
        $looksLikeContact = str_contains($q, '@') || (strlen($digits) >= 7);

        $people = Person::query()
            ->with('user')
            ->where(function ($builder) use ($like, $digits): void {
                $builder->where('email', 'like', $like)
                    ->orWhere('phone', 'like', $like)
                    ->orWhere('phone_digits', 'like', '%'.$digits.'%')
                    ->orWhere('display_name', 'like', $like)
                    ->orWhere('first_name', 'like', $like)
                    ->orWhere('last_name', 'like', $like)
                    ->orWhere('person_no', 'like', $like);
            })
            ->limit($limit)
            ->get()
            ->map(fn (Person $person): array => [
                'source' => 'person',
                'id' => $person->uuid,
                'person_id' => $person->id,
                'user_id' => $person->user_id,
                'name' => $person->display_name,
                'email' => $person->email,
                'phone' => $person->phone,
                'matched_by_contact' => $looksLikeContact,
            ]);

        $users = User::query()
            ->where(function ($builder) use ($like, $digits): void {
                $builder->where('email', 'like', $like)
                    ->orWhere('name', 'like', $like);
                if ($digits !== '') {
                    $builder->orWhere('phone', 'like', '%'.$digits.'%');
                }
            })
            ->limit($limit)
            ->get()
            ->map(fn (User $user): array => [
                'source' => $this->userAudience($user),
                'id' => $user->uuid,
                'person_id' => null,
                'user_id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone ?? null,
                'matched_by_contact' => $looksLikeContact,
            ]);

        return $this->dedupe($people->concat($users))->take($limit)->values()->all();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return Collection<int, array{email:?string, phone:?string, name:?string, user_id:?int, person_id:?int}>
     */
    public function dedupe(Collection $rows): Collection
    {
        return $rows
            ->filter(fn (array $row): bool => filled($row['email'] ?? null) || filled($row['phone'] ?? null))
            ->unique(function (array $row): string {
                if (! empty($row['person_id'])) {
                    return 'person:'.$row['person_id'];
                }
                if (! empty($row['user_id'])) {
                    return 'user:'.$row['user_id'];
                }
                $email = strtolower(trim((string) ($row['email'] ?? '')));
                if ($email !== '') {
                    return 'email:'.$email;
                }

                return 'phone:'.preg_replace('/\D+/', '', (string) ($row['phone'] ?? ''));
            })
            ->values();
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public function isValidForChannel(array $row, string $channel): bool
    {
        if ($channel === 'email') {
            return filter_var((string) ($row['email'] ?? ''), FILTER_VALIDATE_EMAIL) !== false;
        }

        $phone = PhoneNumberNormalizer::normalize((string) ($row['phone'] ?? ''))['phone'] ?? $row['phone'] ?? null;

        return filled($phone) && strlen(preg_replace('/\D+/', '', (string) $phone) ?? '') >= 7;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array{email:?string, phone:?string, name:?string, user_id:?int, person_id:?int}>
     */
    private function lmsRecipients(array $filters): Collection
    {
        $query = Enrollment::query()->with(['user', 'member', 'course']);
        if (! empty($filters['course_id'])) {
            $query->where('course_id', (int) $filters['course_id']);
        }
        if (! empty($filters['enrollment_status'])) {
            $query->where('status', $filters['enrollment_status']);
        }
        if (! empty($filters['learner_type'])) {
            $query->where('learner_type', $filters['learner_type']);
        }
        if (! empty($filters['school_id'])) {
            $query->whereHas('course', fn ($q) => $q->where('school_id', (int) $filters['school_id']));
        }
        if (! empty($filters['program_module_id'])) {
            $query->whereHas('course', fn ($q) => $q->where('program_module_id', (int) $filters['program_module_id']));
        }
        if (! empty($filters['lesson_id'])) {
            $query->whereHas('lessonProgress', fn ($q) => $q->where('lesson_id', (int) $filters['lesson_id']));
        }
        if (! empty($filters['assignment_status'])) {
            $query->whereHas('assignmentSubmissions', fn ($q) => $q->where('status', $filters['assignment_status']));
        }

        return $query->get()->map(function (Enrollment $enrollment): array {
            $user = $enrollment->user;
            $member = $enrollment->member;

            return [
                'email' => $user?->email ?: $member?->email,
                'phone' => $user?->phone ?? $member?->phone,
                'name' => $user?->name ?: $member?->display_name,
                'user_id' => $user?->id,
                'person_id' => $member?->person_id,
            ];
        });
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array{email:?string, phone:?string, name:?string, user_id:?int, person_id:?int}>
     */
    private function counselingRecipients(array $filters): Collection
    {
        $query = CounsellingCase::query()->with(['user', 'member', 'payments']);
        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (! empty($filters['counsellor_id'])) {
            $query->where('counsellor_id', (int) $filters['counsellor_id']);
        }
        if (! empty($filters['category_id'])) {
            $query->where('category_id', (int) $filters['category_id']);
        }
        if (! empty($filters['client_type'])) {
            $query->where('client_type', $filters['client_type']);
        }
        $assigned = strtolower((string) ($filters['assigned'] ?? ''));
        if ($assigned === 'yes') {
            $query->whereNotNull('counsellor_id');
        } elseif ($assigned === 'no') {
            $query->whereNull('counsellor_id');
        }

        return $query->get()->filter(function (CounsellingCase $case) use ($filters): bool {
            $payment = strtolower(trim((string) ($filters['payment'] ?? '')));
            if ($payment === '') {
                return true;
            }
            $paid = $case->payments->contains(function ($row): bool {
                $status = $row->status instanceof \BackedEnum ? $row->status->value : (string) $row->status;

                return strtolower($status) === 'paid';
            });

            return $payment === 'paid' ? $paid : ! $paid;
        })->map(fn (CounsellingCase $case): array => [
            'email' => $case->client_email ?: $case->user?->email,
            'phone' => $case->client_phone ?: $case->user?->phone,
            'name' => $case->client_name ?: $case->user?->name,
            'user_id' => $case->user_id,
            'person_id' => $case->member?->person_id,
        ]);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array{email:?string, phone:?string, name:?string, user_id:?int, person_id:?int}>
     */
    private function membershipRecipients(array $filters): Collection
    {
        $query = Member::query()->with('user');
        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (! empty($filters['approval_status'])) {
            $query->where('approval_status', $filters['approval_status']);
        }
        if (! empty($filters['ministry_id'])) {
            $query->where('ministry_id', (int) $filters['ministry_id']);
        }
        if (! empty($filters['interview_status'])) {
            $query->whereHas('interviews', fn ($q) => $q->where('status', $filters['interview_status']));
        }

        return $query->get()->map(fn (Member $member): array => [
            'email' => $member->email ?: $member->user?->email,
            'phone' => $member->phone ?: $member->user?->phone,
            'name' => $member->display_name ?: $member->user?->name,
            'user_id' => $member->user_id,
            'person_id' => $member->person_id,
        ]);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array{email:?string, phone:?string, name:?string, user_id:?int, person_id:?int}>
     */
    private function businessReviewRecipients(array $filters): Collection
    {
        $query = BusinessReview::query();
        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (! empty($filters['country'])) {
            $query->where('country', 'like', '%'.$filters['country'].'%');
        }

        return $query->get()->map(fn (BusinessReview $review): array => [
            'email' => $review->email,
            'phone' => $review->phone,
            'name' => $review->full_name ?: trim(($review->first_name ?? '').' '.($review->last_name ?? '')),
            'user_id' => $review->user_id,
            'person_id' => null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array{email:?string, phone:?string, name:?string, user_id:?int, person_id:?int}>
     */
    private function formRecipients(array $filters, ?string $type): Collection
    {
        $query = CmsFormSubmission::query();
        if ($type) {
            $query->where('type', $type);
        }
        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        $category = strtolower(trim((string) ($filters['category'] ?? '')));

        return $query->get()->filter(function (CmsFormSubmission $submission) use ($category): bool {
            if ($category === '') {
                return true;
            }
            $payload = is_array($submission->payload) ? $submission->payload : [];
            $hay = strtolower(trim((string) ($payload['category'] ?? $payload['prayer_category'] ?? $payload['type'] ?? '')));

            return str_contains($hay, $category);
        })->map(function (CmsFormSubmission $submission): array {
            $payload = is_array($submission->payload) ? $submission->payload : [];

            return [
                'email' => $submission->submitter_email ?: ($payload['email'] ?? null),
                'phone' => $payload['phone'] ?? null,
                'name' => $submission->submitter_name ?: ($payload['name'] ?? null),
                'user_id' => null,
                'person_id' => null,
            ];
        });
    }

    private function userAudience(User $user): string
    {
        if ($user->relationLoaded('roles') === false) {
            $user->loadMissing('roles');
        }
        $slugs = $user->roles->pluck('slug')->all();
        if (in_array('super_admin', $slugs, true) || in_array('admin', $slugs, true)) {
            return 'admin';
        }
        if (in_array('staff', $slugs, true)) {
            return 'staff';
        }

        return $user->type === 'visitor' ? 'visitor' : 'user';
    }

    private function maskEmail(?string $email): ?string
    {
        if ($email === null || $email === '') {
            return null;
        }
        $parts = explode('@', $email, 2);
        $local = $parts[0];
        $visible = substr($local, 0, 1);

        return $visible.'***'.(isset($parts[1]) ? '@'.$parts[1] : '');
    }

    private function maskPhone(?string $phone): ?string
    {
        if ($phone === null || $phone === '') {
            return null;
        }
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        if (strlen($digits) < 4) {
            return '***';
        }

        return '***'.substr($digits, -4);
    }
}
