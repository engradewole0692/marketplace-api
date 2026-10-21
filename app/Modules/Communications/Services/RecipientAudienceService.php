<?php

declare(strict_types=1);

namespace App\Modules\Communications\Services;

use App\Models\Member;
use App\Models\Person;
use App\Models\User;
use App\Modules\BusinessReview\Models\BusinessReview;
use App\Modules\Cms\Enums\FormSubmissionStatus;
use App\Modules\Cms\Enums\FormSubmissionType;
use App\Modules\Cms\Models\CmsFormSubmission;
use App\Modules\Counselling\Models\CounsellingCase;
use App\Modules\Communications\Support\PhoneNumberService;
use App\Modules\Lms\Models\Enrollment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use libphonenumber\PhoneNumberUtil;

/**
 * Platform-wide recipient resolution for the Communications workspace.
 *
 * The same resolve() runs for the preview and again at send time, so the audience that is sent is
 * the audience that was previewed, revalidated against current data and consent.
 * Does not create Person records. Module filters only use fields the module stores.
 */
final class RecipientAudienceService
{
    private const CHANNELS = ['email', 'sms', 'whatsapp'];

    /** @var array<int, string>|null */
    private ?array $countryCodes = null;

    public function __construct(
        private readonly PhoneNumberService $phones,
        private readonly CommunicationConsentService $consent,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function modules(?User $actor = null): array
    {
        $countries = $this->options('cms_countries', 'id', 'name');
        $ministries = $this->options('cms_ministries', 'id', 'name');
        $memberStatuses = $this->enumOptions(\App\Enums\MemberStatus::class);
        $approvalStatuses = $this->enumOptions(\App\Enums\MemberApprovalStatus::class);
        $interviewStatuses = $this->enumOptions(\App\Enums\MemberInterviewStatus::class);
        $yesNo = [['value' => 'yes', 'label' => 'Yes'], ['value' => 'no', 'label' => 'No']];

        $modules = [
            [
                'id' => 'general',
                'label' => 'General platform users',
                'description' => 'User accounts, optionally narrowed by role, membership country or ministry.',
                'filters' => [
                    $this->select('audience', 'Audience', $this->plain(['all', 'members', 'visitors', 'staff', 'admins'])),
                    $this->select('role_slug', 'Role', $this->options('roles', 'slug', 'name')),
                    $this->select('country_id', 'Member country', $countries),
                    $this->select('ministry_id', 'Member ministry', $ministries),
                ],
            ],
            [
                'id' => 'events',
                'label' => 'Events',
                'description' => 'Registrants of a specific event. Cancelled and declined registrations are never included.',
                'requires' => ['event_id'],
                'filters' => [
                    $this->select('event_id', 'Event', $this->eventOptions()),
                    $this->select('event_session_id', 'Session', $this->sessionOptions(), 'event_id'),
                    $this->select('registration_status', 'Registration status', $this->distinctOptions('event_registrations', 'status', ['cancelled', 'declined'])),
                    $this->select('audience', 'Member / visitor', $this->plain(['members', 'visitors'])),
                    $this->select('gender', 'Gender', $this->plain(['male', 'female'])),
                    ['key' => 'category', 'label' => 'Participant category', 'type' => 'text'],
                    ['key' => 'country', 'label' => 'Country', 'type' => 'text'],
                    ['key' => 'state_region', 'label' => 'State / province', 'type' => 'text'],
                    ['key' => 'city', 'label' => 'City', 'type' => 'text'],
                    $this->select('accommodation', 'Accommodation requested', $yesNo),
                    $this->select('occupancy_type', 'Private / shared room', $this->plain(['private', 'shared'])),
                    $this->select('transport', 'Transport requested', $yesNo),
                    $this->select('payment', 'Payment', $this->plain(['paid', 'pending'])),
                    $this->select('attendance', 'Attendance', $this->plain(['attended', 'absent', 'checked_in', 'checked_out'])),
                    $this->select('seat_counting', 'Counts toward seating', $yesNo),
                ],
            ],
            [
                'id' => 'tribe',
                'label' => 'Tribe of Marketplace Ministers registrations',
                'description' => 'People who submitted the Tribe membership application, at any review stage.',
                'filters' => [
                    $this->select('approval_status', 'Application review', $approvalStatuses),
                    $this->select('status', 'Application stage', $memberStatuses),
                    $this->select('interview_status', 'Interview status', $interviewStatuses),
                    $this->select('country_id', 'Country', $countries),
                    ['key' => 'submitted_from', 'label' => 'Submitted from', 'type' => 'date'],
                    ['key' => 'submitted_to', 'label' => 'Submitted to', 'type' => 'date'],
                ],
            ],
            [
                'id' => 'membership',
                'label' => 'Membership',
                'description' => 'Member records by status, ministry and country.',
                'filters' => [
                    $this->select('status', 'Membership status', $memberStatuses),
                    $this->select('approval_status', 'Approval status', $approvalStatuses),
                    $this->select('ministry_id', 'Ministry', $ministries),
                    $this->select('country_id', 'Country', $countries),
                    $this->select('interview_status', 'Interview status', $interviewStatuses),
                ],
            ],
            [
                'id' => 'visitors',
                'label' => 'Visitors',
                'description' => 'User accounts that are not approved members.',
                'filters' => [
                    ['key' => 'registered_from', 'label' => 'Account created from', 'type' => 'date'],
                    ['key' => 'registered_to', 'label' => 'Account created to', 'type' => 'date'],
                ],
            ],
            [
                'id' => 'lms',
                'label' => 'LMS / Learning',
                'description' => 'Course enrollments.',
                'filters' => [
                    $this->select('school_id', 'School', $this->options('lms_schools', 'id', 'title')),
                    $this->select('course_id', 'Course', $this->options('lms_courses', 'id', 'title')),
                    $this->select('enrollment_status', 'Enrollment status', $this->plain(['active', 'completed', 'cancelled', 'expired', 'pending_payment', 'locked'])),
                    $this->select('learner_type', 'Member / public learner', $this->plain(['member', 'public'])),
                ],
            ],
            [
                'id' => 'counseling',
                'label' => 'Counseling',
                'description' => 'Counseling clients. Restricted: requires counselling management permission.',
                'restricted' => true,
                'filters' => [
                    $this->select('status', 'Case status', $this->distinctOptions('counselling_cases', 'status')),
                    $this->select('counsellor_id', 'Counsellor', $this->options('counselling_counsellors', 'id', 'display_name')),
                    $this->select('category_id', 'Category', $this->options('counselling_categories', 'id', 'name')),
                    $this->select('assigned', 'Assigned to a counsellor', $yesNo),
                    $this->select('payment', 'Payment', $this->plain(['paid', 'pending'])),
                    $this->select('client_type', 'Member / visitor', $this->distinctOptions('counselling_cases', 'client_type')),
                ],
            ],
            [
                'id' => 'prayer',
                'label' => 'Prayer',
                'description' => 'Prayer request submitters. Restricted: requires prayer management permission.',
                'restricted' => true,
                'filters' => [
                    $this->select('status', 'Request status', $this->enumOptions(FormSubmissionStatus::class)),
                    ['key' => 'category', 'label' => 'Prayer category', 'type' => 'text'],
                ],
            ],
            [
                'id' => 'ministries',
                'label' => 'Ministries',
                'description' => 'Members assigned to a ministry.',
                'filters' => [
                    $this->select('ministry_id', 'Ministry', $ministries),
                    $this->select('status', 'Membership status', $memberStatuses),
                ],
            ],
            [
                'id' => 'business_review',
                'label' => 'Business Review',
                'description' => 'Business review applicants.',
                'filters' => [
                    $this->select('status', 'Review status', $this->distinctOptions('business_reviews', 'status')),
                    ['key' => 'country', 'label' => 'Country', 'type' => 'text'],
                ],
            ],
            [
                'id' => 'forms',
                'label' => 'Website forms',
                'description' => 'Submitters of public website forms.',
                'filters' => [
                    $this->select('form_type', 'Form type', $this->enumOptions(FormSubmissionType::class)),
                    $this->select('status', 'Submission status', $this->enumOptions(FormSubmissionStatus::class)),
                ],
            ],
        ];

        if ($actor !== null) {
            foreach ($modules as &$module) {
                $module['allowed'] = $this->canUseModule($actor, $module['id']);
            }
            unset($module);
        }

        return $modules;
    }

    public function canUseModule(User $user, string $module): bool
    {
        $module = $module === 'counselling' ? 'counseling' : $module;
        $required = (array) (config('communications.restricted_modules.'.$module) ?? []);

        return $required === [] || $user->hasAnyPermission($required);
    }

    /**
     * Rows as stored by the module, before any de-duplication.
     *
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array<string, mixed>>
     */
    public function collectRaw(array $filters): Collection
    {
        $module = $this->moduleOf($filters);

        return match ($module) {
            'events' => app(BulkEmailService::class)->collectEventRecipients($filters),
            'tribe' => $this->tribeRecipients($filters),
            'membership', 'ministries' => $this->membershipRecipients($filters),
            'visitors' => $this->visitorRecipients($filters),
            'lms' => $this->lmsRecipients($filters),
            'counseling' => $this->counselingRecipients($filters),
            'prayer' => $this->formRecipients($filters, FormSubmissionType::Prayer->value),
            'business_review' => $this->businessReviewRecipients($filters),
            'forms' => $this->formRecipients($filters, isset($filters['form_type']) ? (string) $filters['form_type'] : null),
            default => app(BulkEmailService::class)->collectUserRecipients($filters),
        };
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array<string, mixed>>
     */
    public function collect(array $filters): Collection
    {
        return $this->dedupe($this->collectRaw($filters));
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function moduleOf(array $filters): string
    {
        $module = strtolower(trim((string) ($filters['module'] ?? '')));
        if ($module === '' && (! empty($filters['event_id']) || ! empty($filters['event_session_id']))) {
            $module = 'events';
        }
        if ($module === '' && ! empty($filters['course_id'])) {
            $module = 'lms';
        }

        return $module === 'counselling' ? 'counseling' : ($module ?: 'general');
    }

    /**
     * Resolve the audience into per-channel deliveries and exclusions.
     *
     * @param  array<string, mixed>  $filters
     * @param  list<string>  $channels
     * @return array{
     *   summary: array<string, mixed>,
     *   channels: array<string, array<string, int>>,
     *   deliveries: array<string, list<array<string, mixed>>>,
     *   exclusions: array<string, list<array<string, mixed>>>,
     *   sample: list<array<string, mixed>>
     * }
     */
    public function resolve(array $filters, array $channels, string $messageType = 'promotional', int $sampleSize = 20): array
    {
        $channels = array_values(array_intersect(self::CHANNELS, array_map('strtolower', $channels))) ?: ['email'];
        $raw = $this->collectRaw($filters)->values();
        $people = $this->dedupe($raw)->values();

        $stats = [];
        $deliveries = [];
        $exclusions = [];
        $perPerson = [];

        foreach ($channels as $channel) {
            $stats[$channel] = [
                'eligible' => 0, 'missing' => 0, 'invalid' => 0, 'duplicates_removed' => 0,
                'opted_out' => 0, 'suppressed' => 0, 'no_opt_in' => 0, 'permission_excluded' => 0,
            ];
            $seen = [];
            $candidates = [];

            foreach ($people as $index => $row) {
                [$destination, $reason] = $this->destinationFor($row, $channel);
                if ($destination === null) {
                    $stats[$channel][$reason === 'invalid_email' || $reason === 'invalid_phone' ? 'invalid' : 'missing']++;
                    $this->exclude($exclusions, $perPerson, $channel, $index, $row, null, $reason);

                    continue;
                }
                if (isset($seen[$destination])) {
                    $stats[$channel]['duplicates_removed']++;
                    $this->exclude($exclusions, $perPerson, $channel, $index, $row, $destination, 'duplicate_destination');

                    continue;
                }
                $seen[$destination] = true;
                $candidates[$destination] = $index;
            }

            $decisions = $this->consent->evaluateMany($channel, array_keys($candidates), $messageType);
            foreach ($candidates as $destination => $index) {
                $row = $people[$index];
                $decision = $decisions[$this->consent->key($channel, (string) $destination)] ?? ['allowed' => true, 'reason' => null];
                if (! $decision['allowed']) {
                    $key = in_array($decision['reason'], ['opted_out', 'suppressed', 'no_opt_in'], true) ? $decision['reason'] : 'opted_out';
                    $stats[$channel][$key]++;
                    $this->exclude($exclusions, $perPerson, $channel, $index, $row, (string) $destination, $key);

                    continue;
                }
                $stats[$channel]['eligible']++;
                $deliveries[$channel][] = [
                    'channel' => $channel,
                    'destination' => (string) $destination,
                    'name' => $row['name'] ?? null,
                    'first_name' => $row['first_name'] ?? null,
                    'email' => $row['email'] ?? null,
                    'user_id' => $row['user_id'] ?? null,
                    'person_id' => $row['person_id'] ?? null,
                ];
                $perPerson[$index][$channel] = ['status' => 'eligible', 'reason' => null, 'destination' => $this->mask($channel, (string) $destination)];
            }
        }

        $sample = [];
        foreach ($people->take($sampleSize) as $index => $row) {
            $sample[] = [
                'name' => $row['name'] ?? null,
                'email' => $this->maskEmail($row['email'] ?? null),
                'phone' => PhoneNumberService::mask($row['phone'] ?? null),
                'channels' => $perPerson[$index] ?? [],
            ];
        }

        $eligiblePeople = 0;
        foreach ($people as $index => $row) {
            foreach ($perPerson[$index] ?? [] as $entry) {
                if ($entry['status'] === 'eligible') {
                    $eligiblePeople++;
                    break;
                }
            }
        }

        return [
            'summary' => [
                'module' => $this->moduleOf($filters),
                'channels' => $channels,
                'message_type' => $messageType,
                'matching_records' => $raw->count(),
                'unique_people' => $people->count(),
                'duplicate_records_merged' => $raw->count() - $people->count(),
                'eligible_people' => $eligiblePeople,
                'total_messages' => array_sum(array_map(fn (array $s): int => $s['eligible'], $stats)),
                // This application has no tenant model; access is enforced per module permission.
                'tenant_scope' => 'not_applicable',
            ],
            'channels' => $stats,
            'deliveries' => $deliveries,
            'exclusions' => $exclusions,
            'sample' => $sample,
        ];
    }

    /**
     * Preview payload for the composer (no raw destinations).
     *
     * @param  array<string, mixed>  $filters
     * @param  list<string>|null  $channels
     * @return array<string, mixed>
     */
    public function preview(array $filters, ?array $channels = null, string $messageType = 'promotional'): array
    {
        $channels ??= [strtolower((string) ($filters['channel'] ?? 'email'))];
        $resolved = $this->resolve($filters, $channels, $messageType);
        $primary = $channels[0] ?? 'email';
        $primaryStats = $resolved['channels'][$primary] ?? ['eligible' => 0];

        return [
            ...$resolved['summary'],
            'channel_stats' => $resolved['channels'],
            'sample' => $resolved['sample'],
            // Legacy single-channel fields kept for existing callers.
            'channel' => $primary,
            'total' => $resolved['summary']['unique_people'],
            'valid_count' => $primaryStats['eligible'],
            'invalid_count' => $resolved['summary']['unique_people'] - $primaryStats['eligible'],
            'deduplicated_count' => $resolved['summary']['unique_people'],
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
                    ->orWhere('display_name', 'like', $like)
                    ->orWhere('first_name', 'like', $like)
                    ->orWhere('last_name', 'like', $like)
                    ->orWhere('person_no', 'like', $like);
                if ($digits !== '') {
                    $builder->orWhere('phone_digits', 'like', '%'.$digits.'%');
                }
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
     * Merge rows that describe the same person (same Person, User, email or phone).
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return Collection<int, array<string, mixed>>
     */
    public function dedupe(Collection $rows): Collection
    {
        return $rows
            ->filter(fn (array $row): bool => filled($row['email'] ?? null) || filled($row['phone'] ?? null) || ! empty($row['person_id']) || ! empty($row['user_id']))
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
        return $this->destinationFor($row, $channel)[0] !== null;
    }

    /**
     * Normalized destination for a channel, or the exclusion reason.
     *
     * @param  array<string, mixed>  $row
     * @return array{0: ?string, 1: ?string}
     */
    public function destinationFor(array $row, string $channel): array
    {
        if ($channel === 'email') {
            $email = strtolower(trim((string) ($row['email'] ?? '')));
            if ($email === '') {
                return [null, 'missing_email'];
            }

            return filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? [$email, null] : [null, 'invalid_email'];
        }

        $normalized = $this->phones->normalize(isset($row['phone']) ? (string) $row['phone'] : null, $this->regionHint($row));

        return $normalized['valid'] ? [$normalized['e164'], null] : [null, $normalized['reason'] ?? 'invalid_phone'];
    }

    public function mask(string $channel, ?string $destination): ?string
    {
        return $channel === 'email' ? $this->maskEmail($destination) : PhoneNumberService::mask($destination);
    }

    public function maskEmail(?string $email): ?string
    {
        if ($email === null || $email === '') {
            return null;
        }
        $parts = explode('@', $email, 2);

        return substr($parts[0], 0, 1).'***'.(isset($parts[1]) ? '@'.$parts[1] : '');
    }

    /**
     * @param  array<string, list<array<string, mixed>>>  $exclusions
     * @param  array<int, array<string, array<string, mixed>>>  $perPerson
     * @param  array<string, mixed>  $row
     */
    private function exclude(array &$exclusions, array &$perPerson, string $channel, int $index, array $row, ?string $destination, string $reason): void
    {
        $exclusions[$channel][] = [
            'channel' => $channel,
            'destination' => $destination,
            'name' => $row['name'] ?? null,
            'email' => $row['email'] ?? null,
            'user_id' => $row['user_id'] ?? null,
            'person_id' => $row['person_id'] ?? null,
            'reason' => $reason,
        ];
        $perPerson[$index][$channel] = ['status' => 'excluded', 'reason' => $reason, 'destination' => $this->mask($channel, $destination)];
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function regionHint(array $row): ?string
    {
        if (! empty($row['region']) && is_string($row['region'])) {
            return $row['region'];
        }
        if (! empty($row['phone_country_code'])) {
            $dial = (int) preg_replace('/\D+/', '', (string) $row['phone_country_code']);
            if ($dial > 0) {
                $region = PhoneNumberUtil::getInstance()->getRegionCodeForCountryCode($dial);
                if ($region !== 'ZZ') {
                    return $region;
                }
            }
        }
        if (! empty($row['country_id'])) {
            return $this->countryCode((int) $row['country_id']);
        }

        return null;
    }

    private function countryCode(int $countryId): ?string
    {
        if ($this->countryCodes === null) {
            $this->countryCodes = Schema::hasTable('cms_countries')
                ? DB::table('cms_countries')->whereNotNull('code')->pluck('code', 'id')->map(fn ($c) => strtoupper((string) $c))->all()
                : [];
        }
        $code = $this->countryCodes[$countryId] ?? null;

        return is_string($code) && strlen($code) === 2 ? $code : null;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array<string, mixed>>
     */
    private function tribeRecipients(array $filters): Collection
    {
        $query = Member::query()->with('user')->whereNotNull('application_number');
        $this->applyMemberFilters($query, $filters);
        if (! empty($filters['submitted_from'])) {
            $query->where('created_at', '>=', Carbon::parse((string) $filters['submitted_from'], config('communications.timezone'))->startOfDay()->utc());
        }
        if (! empty($filters['submitted_to'])) {
            $query->where('created_at', '<=', Carbon::parse((string) $filters['submitted_to'], config('communications.timezone'))->endOfDay()->utc());
        }

        return $query->get()->map(fn (Member $member): array => $this->memberRow($member));
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array<string, mixed>>
     */
    private function membershipRecipients(array $filters): Collection
    {
        $query = Member::query()->with('user');
        $this->applyMemberFilters($query, $filters);

        return $query->get()->map(fn (Member $member): array => $this->memberRow($member));
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<Member>  $query
     * @param  array<string, mixed>  $filters
     */
    private function applyMemberFilters($query, array $filters): void
    {
        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (! empty($filters['approval_status'])) {
            $query->where('approval_status', $filters['approval_status']);
        }
        if (! empty($filters['ministry_id'])) {
            $query->where('ministry_id', (int) $filters['ministry_id']);
        }
        if (! empty($filters['country_id'])) {
            $query->where('country_id', (int) $filters['country_id']);
        }
        if (! empty($filters['interview_status'])) {
            $query->whereHas('interviews', fn ($q) => $q->where('status', $filters['interview_status']));
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function memberRow(Member $member): array
    {
        return [
            'email' => $member->email ?: $member->user?->email,
            'phone' => $member->phone ?: $member->user?->phone,
            'name' => $member->display_name ?: trim(($member->first_name ?? '').' '.($member->last_name ?? '')) ?: $member->user?->name,
            'first_name' => $member->first_name,
            'user_id' => $member->user_id,
            'person_id' => $member->person_id,
            'country_id' => $member->country_id,
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array<string, mixed>>
     */
    private function visitorRecipients(array $filters): Collection
    {
        $query = User::query()
            ->where('status', '!=', 'inactive')
            ->whereDoesntHave('member', fn ($q) => $q->where('approval_status', 'approved'));
        if (! empty($filters['registered_from'])) {
            $query->where('created_at', '>=', Carbon::parse((string) $filters['registered_from'], config('communications.timezone'))->startOfDay()->utc());
        }
        if (! empty($filters['registered_to'])) {
            $query->where('created_at', '<=', Carbon::parse((string) $filters['registered_to'], config('communications.timezone'))->endOfDay()->utc());
        }

        return $query->get(['id', 'name', 'first_name', 'email', 'phone'])->map(fn (User $user): array => [
            'email' => $user->email,
            'phone' => $user->phone,
            'name' => $user->name,
            'first_name' => $user->first_name,
            'user_id' => $user->id,
            'person_id' => null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array<string, mixed>>
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
                'first_name' => $user?->first_name ?: $member?->first_name,
                'user_id' => $user?->id,
                'person_id' => $member?->person_id,
                'country_id' => $member?->country_id,
            ];
        });
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array<string, mixed>>
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
            'country_id' => $case->member?->country_id,
        ]);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array<string, mixed>>
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
            'first_name' => $review->first_name,
            'user_id' => $review->user_id,
            'person_id' => null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array<string, mixed>>
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
        $user->loadMissing('roles');
        $slugs = $user->roles->pluck('slug')->all();
        if (in_array('super_admin', $slugs, true) || in_array('admin', $slugs, true)) {
            return 'admin';
        }
        if (in_array('staff', $slugs, true)) {
            return 'staff';
        }

        return 'user';
    }

    /**
     * @param  list<array{value: string, label: string}>  $options
     * @return array<string, mixed>
     */
    private function select(string $key, string $label, array $options, ?string $dependsOn = null): array
    {
        return array_filter([
            'key' => $key,
            'label' => $label,
            'type' => 'select',
            'options' => $options,
            'depends_on' => $dependsOn,
        ], fn ($v) => $v !== null);
    }

    /**
     * @param  list<string>  $values
     * @return list<array{value: string, label: string}>
     */
    private function plain(array $values): array
    {
        return array_map(fn (string $v): array => ['value' => $v, 'label' => ucfirst(str_replace('_', ' ', $v))], $values);
    }

    /**
     * @param  class-string<\BackedEnum>  $enum
     * @return list<array{value: string, label: string}>
     */
    private function enumOptions(string $enum): array
    {
        if (! enum_exists($enum)) {
            return [];
        }

        return $this->plain(array_map(fn (\BackedEnum $case): string => (string) $case->value, $enum::cases()));
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function options(string $table, string $valueColumn, string $labelColumn, int $limit = 300): array
    {
        if (! Schema::hasTable($table)) {
            return [];
        }
        $query = DB::table($table)->select([$valueColumn, $labelColumn])->orderBy($labelColumn)->limit($limit);
        if (Schema::hasColumn($table, 'deleted_at')) {
            $query->whereNull('deleted_at');
        }

        return $query->get()
            ->filter(fn ($row) => $row->{$valueColumn} !== null && $row->{$labelColumn} !== null)
            ->map(fn ($row): array => ['value' => (string) $row->{$valueColumn}, 'label' => (string) $row->{$labelColumn}])
            ->values()
            ->all();
    }

    /**
     * @param  list<string>  $exclude
     * @return list<array{value: string, label: string}>
     */
    private function distinctOptions(string $table, string $column, array $exclude = []): array
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
            return [];
        }
        $values = DB::table($table)->whereNotNull($column)->distinct()->orderBy($column)->limit(100)->pluck($column)
            ->map(fn ($v) => (string) $v)
            ->reject(fn (string $v) => $v === '' || in_array($v, $exclude, true))
            ->values()
            ->all();

        return $this->plain($values);
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function eventOptions(): array
    {
        if (! Schema::hasTable('events')) {
            return [];
        }

        return DB::table('events')->whereNull('deleted_at')->orderByDesc('starts_at')->limit(200)
            ->get(['uuid', 'title', 'starts_at'])
            ->map(fn ($row): array => [
                'value' => (string) $row->uuid,
                'label' => $row->title.($row->starts_at ? ' ('.Carbon::parse($row->starts_at)->timezone(config('communications.timezone'))->format('j M Y').')' : ''),
            ])->all();
    }

    /**
     * @return list<array{value: string, label: string, parent: string}>
     */
    private function sessionOptions(): array
    {
        if (! Schema::hasTable('event_sessions')) {
            return [];
        }

        return DB::table('event_sessions')
            ->join('events', 'events.id', '=', 'event_sessions.event_id')
            ->whereNull('events.deleted_at')
            ->orderBy('event_sessions.starts_at')
            ->limit(500)
            ->get(['event_sessions.uuid', 'event_sessions.title', 'events.uuid as event_uuid'])
            ->map(fn ($row): array => ['value' => (string) $row->uuid, 'label' => (string) $row->title, 'parent' => (string) $row->event_uuid])
            ->all();
    }
}
