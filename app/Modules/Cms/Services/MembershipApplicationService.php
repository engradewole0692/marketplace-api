<?php

declare(strict_types=1);

namespace App\Modules\Cms\Services;

use App\Contracts\ServiceContract;
use App\Enums\MemberApprovalStatus;
use App\Enums\MemberStatus;
use App\Models\Member;
use App\Models\User;
use App\Modules\Cms\Enums\FormSubmissionType;
use App\Modules\Cms\Models\CmsCountry;
use App\Modules\Cms\Models\CmsMinistry;
use App\Services\Membership\MemberManagementService;
use App\Services\Membership\MemberNotificationQueueService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

final class MembershipApplicationService implements ServiceContract
{
  public function __construct(
    private readonly MemberManagementService $memberManagementService,
    private readonly FormSubmissionService $formSubmissionService,
    private readonly MemberNotificationQueueService $notificationQueueService,
  ) {}

  /**
   * @param  array<string, mixed>  $payload
   */
  public function submit(array $payload): Member
  {
    $countryId = $this->resolveCountryId($payload['country'] ?? $payload['countryCode'] ?? null);
    $preferredMinistryId = $this->resolveMinistryId($payload['preferredMinistry'] ?? $payload['preferred_ministry'] ?? null);

    $references = [];
    if (! empty($payload['references']) && is_array($payload['references'])) {
      $references = $payload['references'];
    }

    $trackingToken = Str::random(48);

    $actor = Auth::user();
    $linkedUserId = null;
    $email = strtolower(trim((string) ($payload['email'] ?? '')));
    if ($actor instanceof User && $email !== '' && strtolower((string) $actor->email) === $email) {
      $linkedUserId = $actor->id;
    } elseif ($email !== '') {
      $existing = User::query()->whereRaw('LOWER(email) = ?', [$email])->first();
      if ($existing !== null) {
        $linkedUserId = $existing->id;
      }
    }

    $member = $this->memberManagementService->createFromPublicApplication([
      'title' => $payload['title'] ?? null,
      'first_name' => $payload['firstName'] ?? $payload['first_name'] ?? '',
      'middle_name' => $payload['middleName'] ?? $payload['middle_name'] ?? null,
      'last_name' => $payload['lastName'] ?? $payload['last_name'] ?? '',
      'email' => $payload['email'] ?? '',
      'phone' => $payload['phone'] ?? null,
      'alternate_phone' => $payload['alternatePhone'] ?? $payload['alternate_phone'] ?? null,
      'gender' => $payload['gender'] ?? null,
      // Public join form posts `dob` (HTML date input); accept camel/snake aliases too.
      'date_of_birth' => $payload['dob']
        ?? $payload['dateOfBirth']
        ?? $payload['date_of_birth']
        ?? null,
      'occupation' => $payload['occupation'] ?? null,
      'profession' => $payload['profession'] ?? $payload['occupation'] ?? null,
      'organization' => $payload['organization'] ?? $payload['employer'] ?? null,
      'marketplace_sector' => $payload['marketplaceSector'] ?? $payload['marketplace_sector'] ?? $payload['industry'] ?? null,
      'skills' => $this->normalizeList($payload['skills'] ?? null),
      'languages' => $this->normalizeList($payload['languages'] ?? null),
      'gifts' => $this->normalizeList($payload['gifts'] ?? $payload['spiritualGifts'] ?? null),
      'ministry_interests' => $this->normalizeList($payload['interests'] ?? $payload['ministryInterests'] ?? null),
      'biography' => $payload['biography'] ?? $payload['testimony'] ?? $payload['whyJoin'] ?? null,
      'church_name' => $payload['churchName'] ?? $payload['church_name'] ?? null,
      'church_address' => $payload['churchAddress'] ?? $payload['church_address'] ?? null,
      // Do not cast free-text ministryExperience into years_of_experience.
      'years_of_experience' => $this->normalizeInt($payload['yearsOfExperience'] ?? $payload['years_of_experience'] ?? null),
      'years_in_faith' => $this->normalizeYearsInFaith($payload['yearsInFaith'] ?? $payload['years_in_faith'] ?? null),
      'education' => $payload['education'] ?? null,
      'availability' => $payload['availability'] ?? null,
      'city' => $payload['city'] ?? null,
      'state' => $payload['state'] ?? null,
      'country_id' => $countryId,
      'preferred_ministry_id' => $preferredMinistryId,
      'references' => $this->buildApplicationReferences($payload, $references),
      'status' => MemberStatus::ApplicationSubmitted->value,
      'approval_status' => MemberApprovalStatus::Pending->value,
      'joined_at' => now()->toDateString(),
      'application_tracking_token' => $trackingToken,
      'user_id' => $linkedUserId,
      'addresses' => $this->buildAddresses($payload, $countryId),
      'contacts' => $this->buildEmergencyContacts($payload),
    ]);

    // application_number mirrors membership_number for applicant-facing UX.
    if ($member->application_number === null) {
      $member->application_number = $member->membership_number;
      $member->save();
    }

    $frontend = rtrim((string) config('app-frontend.url', config('app.url')), '/');
    $statusUrl = $frontend.'/membership/status?token='.$trackingToken;

    try {
      $this->formSubmissionService->submit(
        FormSubmissionType::MembershipApplication,
        array_merge($payload, [
          'member_uuid' => $member->uuid,
          'application_number' => $member->application_number,
          'tracking_token' => $trackingToken,
        ]),
      );
    } catch (\Throwable $exception) {
      report($exception);
    }

    try {
      $this->notificationQueueService->queueMany($member, [
        [
          'channel' => 'in_app',
          'template' => 'application_submitted',
          'payload' => [
            'application_number' => $member->application_number,
            'status_url' => $statusUrl,
          ],
        ],
      ]);
    } catch (\Throwable $exception) {
      report($exception);
    }

    return $member->fresh() ?? $member;
  }

  private function notifyMembershipAdmins(Member $member, string $statusUrl): void
  {
    $admins = User::query()
      ->where(function ($q): void {
        $q->whereHas('permissions', fn ($p) => $p->whereIn('slug', ['members.manage', 'members.approve', 'members.view']))
          ->orWhereHas('roles.permissions', fn ($p) => $p->whereIn('slug', ['members.manage', 'members.approve', 'members.view']))
          ->orWhereHas('roles', fn ($r) => $r->whereIn('slug', ['super-admin', 'super_admin', 'admin']));
      })
      ->limit(25)
      ->get();

    foreach ($admins as $admin) {
      if ($admin->email === null || $admin->email === '') {
        continue;
      }
      $this->notificationQueueService->queueMany($member, [
        [
          'channel' => 'email',
          'template' => 'application_submitted_admin',
          'payload' => [
            'email' => $admin->email,
            'admin_name' => $admin->name,
            'applicant_name' => $member->fullName(),
            'application_number' => $member->application_number,
            'membership_number' => $member->membership_number,
            'admin_url' => rtrim((string) config('app-frontend.url', config('app.url')), '/').'/admin/members/applications/'.$member->uuid,
          ],
        ],
        [
          'channel' => 'in_app',
          'template' => 'application_submitted_admin',
          'payload' => [
            'user_id' => $admin->id,
            'application_number' => $member->application_number,
            'member_uuid' => $member->uuid,
          ],
        ],
      ]);
    }
  }

  private function resolveCountryId(mixed $value): ?int
  {
    if ($value === null || $value === '') {
      return null;
    }

    if (is_numeric($value)) {
      return (int) $value;
    }

    $slug = strtolower((string) $value);

    return CmsCountry::query()
      ->where('slug', $slug)
      ->orWhere('code', strtoupper($slug))
      ->orWhere('name', 'like', $slug)
      ->value('id');
  }

  private function resolveMinistryId(mixed $value): ?int
  {
    if ($value === null || $value === '') {
      return null;
    }

    if (is_numeric($value)) {
      return (int) $value;
    }

    $slug = strtolower((string) $value);

    return CmsMinistry::query()->where('slug', $slug)->orWhere('name', 'like', $slug)->value('id');
  }

  /**
   * @return list<string>|null
   */
  private function normalizeList(mixed $value): ?array
  {
    if ($value === null || $value === '') {
      return null;
    }

    if (is_array($value)) {
      return array_values(array_filter(array_map('strval', $value)));
    }

    return array_values(array_filter(array_map('trim', preg_split('/[,;]+/', (string) $value) ?: [])));
  }

  private function normalizeInt(mixed $value): ?int
  {
    if ($value === null || $value === '') {
      return null;
    }

    if (! is_numeric($value)) {
      return null;
    }

    return (int) $value;
  }

  /**
   * Map join-form ranges (e.g. "1–3") onto years_in_faith without corrupting free text.
   */
  private function normalizeYearsInFaith(mixed $value): ?int
  {
    if ($value === null || $value === '') {
      return null;
    }

    if (is_numeric($value)) {
      return (int) $value;
    }

    $key = str_replace(['–', '—'], '-', trim((string) $value));

    return match ($key) {
      '<1' => 0,
      '1-3' => 2,
      '4-7' => 5,
      '8-15' => 10,
      '16+' => 16,
      default => null,
    };
  }

  /**
   * Persist original join-form fields for admin Applicant View without a schema change.
   *
   * @param  array<string, mixed>  $payload
   * @param  list<mixed>  $references
   * @return array<string, mixed>
   */
  private function buildApplicationReferences(array $payload, array $references): array
  {
    $application = array_filter([
      'marital_status' => $payload['maritalStatus'] ?? $payload['marital_status'] ?? null,
      'spouse_first_name' => $payload['spouseFirstName'] ?? null,
      'spouse_last_name' => $payload['spouseLastName'] ?? null,
      'spouse_email' => $payload['spouseEmail'] ?? null,
      'spouse_phone' => $payload['spousePhone'] ?? null,
      'years_in_faith_label' => $payload['yearsInFaith'] ?? null,
      'ministry_experience' => $payload['ministryExperience'] ?? null,
      'leadership' => $payload['leadership'] ?? null,
      'affiliation' => $payload['affiliation'] ?? null,
      'why_join' => $payload['whyJoin'] ?? null,
      'personal_vision' => $payload['personalVision'] ?? null,
      'testimony' => $payload['testimony'] ?? null,
      'prayer_requests' => $payload['prayerRequests'] ?? null,
      'declaration' => $payload['declaration'] ?? null,
    ], static fn ($value) => $value !== null && $value !== '');

    return array_filter([
      'contacts' => $references !== [] ? $references : null,
      'application' => $application !== [] ? $application : null,
    ]);
  }

  /**
   * @param  array<string, mixed>  $payload
   * @return list<array<string, mixed>>
   */
  private function buildAddresses(array $payload, ?int $countryId): array
  {
    $line = $payload['address'] ?? $payload['addressLine1'] ?? null;
    if ($line === null && empty($payload['city']) && empty($payload['state'])) {
      return [];
    }

    return [[
      'address_type' => 'home',
      'address_line_1' => $line,
      'city' => $payload['city'] ?? null,
      'state' => $payload['state'] ?? null,
      'country_code' => is_string($payload['country'] ?? null) ? strtoupper(substr($payload['country'], 0, 3)) : null,
      'is_primary' => true,
    ]];
  }

  /**
   * @param  array<string, mixed>  $payload
   * @return list<array<string, mixed>>
   */
  private function buildEmergencyContacts(array $payload): array
  {
    $name = $payload['nextOfKin'] ?? $payload['next_of_kin'] ?? null;
    if ($name === null) {
      return [];
    }

    return [[
      'contact_type' => 'emergency',
      'name' => $name,
      'relationship' => $payload['kinRelationship'] ?? $payload['kin_relationship'] ?? null,
      'phone' => $payload['kinPhone'] ?? $payload['kin_phone'] ?? null,
      'email' => $payload['kinEmail'] ?? $payload['kin_email'] ?? null,
      'is_primary' => true,
    ]];
  }
}
