<?php

declare(strict_types=1);

namespace App\Modules\Lms\Services;

use App\Contracts\ServiceContract;
use App\Enums\ApiErrorCode;
use App\Exceptions\BusinessException;
use App\Models\Member;
use App\Models\User;
use App\Modules\Communications\Services\CommunicationLmsBridge;
use App\Modules\Lms\Enums\CourseStatus;
use App\Modules\Lms\Enums\EnrollmentStatus;
use App\Modules\Lms\Enums\LearnerType;
use App\Modules\Lms\Enums\SchoolStatus;
use App\Modules\Lms\Models\Course;
use App\Modules\Lms\Models\Enrollment;
use App\Modules\Lms\Models\LmsSchool;
use App\Modules\Lms\Models\SchoolEnrollment;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

final class SchoolEnrollmentService implements ServiceContract
{
  public function __construct(
    private readonly PricingEngine $pricingEngine,
    private readonly EnrollmentService $enrollmentService,
    private readonly LmsAccessService $accessService,
    private readonly ProgramProgressionService $programProgression,
    private readonly CommunicationLmsBridge $communicationLms,
  ) {}

  /**
   * @param  array<string, mixed>  $filters
   */
  public function paginate(array $filters = []): LengthAwarePaginator
  {
    $query = SchoolEnrollment::query()
      ->with(['school', 'user', 'member'])
      ->latest('enrolled_at');

    if (! empty($filters['status'])) {
      $query->where('status', $filters['status']);
    }
    if (! empty($filters['school_id'])) {
      $schoolId = LmsSchool::query()->where('uuid', $filters['school_id'])->value('id');
      if ($schoolId) {
        $query->where('school_id', $schoolId);
      }
    }
    if (! empty($filters['user_id'])) {
      $userId = User::query()->where('uuid', $filters['user_id'])->value('id');
      if ($userId) {
        $query->where('user_id', $userId);
      }
    }

    return $query->paginate(min(100, max(1, (int) ($filters['per_page'] ?? 25))));
  }

  public function enroll(LmsSchool $school, User $user, LearnerType $learnerType): SchoolEnrollment
  {
    if ($school->status !== SchoolStatus::Published) {
      throw new BusinessException('School is not open for enrollment.', ApiErrorCode::UnprocessableEntity, null, 422);
    }

    $existing = SchoolEnrollment::query()
      ->where('school_id', $school->id)
      ->where('user_id', $user->id)
      ->first();

    if ($existing && $existing->status !== EnrollmentStatus::Cancelled) {
      return $existing->load(['school', 'user']);
    }

    return DB::transaction(function () use ($school, $user, $learnerType, $existing): SchoolEnrollment {
      $member = Member::query()->where('user_id', $user->id)->first();

      if ($learnerType === LearnerType::Member && ($member === null || ! $member->qualifiesForMemberPricing())) {
        $learnerType = LearnerType::Public;
      }

      $pricing = $this->pricingEngine->resolveSchool($school, $learnerType);
      if ($this->accessService->bypassesPaidLmsAccess($user)) {
        $pricing = [
          'amount' => 0.0,
          'currency' => $school->currency ?: 'USD',
          'is_free' => true,
          'list_price' => 0.0,
          'promotional' => false,
          'coupon_applied' => false,
          'coupon_code' => null,
          'audience' => $learnerType->value,
        ];
      }

      $status = $pricing['is_free'] || $pricing['amount'] <= 0
        ? EnrollmentStatus::Active
        : EnrollmentStatus::PendingPayment;

      $payload = [
        'school_id' => $school->id,
        'user_id' => $user->id,
        'member_id' => $member?->id,
        'learner_type' => $learnerType,
        'status' => $status,
        'enrolled_at' => now(),
        'progress_percent' => 0,
        'price_paid' => $pricing['amount'],
        'currency' => $pricing['currency'],
        'metadata' => ['pricing' => $pricing],
      ];

      if ($existing && $existing->status === EnrollmentStatus::Cancelled) {
        $existing->fill($payload)->save();
        $enrollment = $existing;
      } else {
        $enrollment = SchoolEnrollment::query()->create($payload);
      }

      if ($status === EnrollmentStatus::Active) {
        $this->syncCourseEnrollments($enrollment);
        $this->communicationLms->notifySchoolEnrollment($enrollment->fresh(['school', 'user']));
      }

      return $enrollment->load(['school', 'user']);
    });
  }

  public function activate(SchoolEnrollment $enrollment): SchoolEnrollment
  {
    $enrollment->update([
      'status' => EnrollmentStatus::Active,
      'enrolled_at' => $enrollment->enrolled_at ?? now(),
    ]);

    $enrollment = $enrollment->fresh(['school', 'user']);
    $this->syncCourseEnrollments($enrollment);
    $this->communicationLms->notifySchoolEnrollment($enrollment, true);

    return $enrollment;
  }

  public function cancel(SchoolEnrollment $enrollment): SchoolEnrollment
  {
    $enrollment->update([
      'status' => EnrollmentStatus::Cancelled,
      'cancelled_at' => now(),
    ]);

    return $enrollment->fresh(['school', 'user']);
  }

  public function hasActiveAccess(User $user, LmsSchool $school): bool
  {
    return SchoolEnrollment::query()
      ->where('school_id', $school->id)
      ->where('user_id', $user->id)
      ->whereIn('status', [
        EnrollmentStatus::Active->value,
        EnrollmentStatus::Completed->value,
      ])
      ->exists();
  }

  public function syncCourseEnrollments(SchoolEnrollment $schoolEnrollment): void
  {
    $courses = Course::query()
      ->where('school_id', $schoolEnrollment->school_id)
      ->where('status', CourseStatus::Published)
      ->get();

    $user = $schoolEnrollment->user;
    if (! $user) {
      return;
    }

    $schoolEnrollment->loadMissing('school');

    $learnerType = $schoolEnrollment->learner_type instanceof LearnerType
      ? $schoolEnrollment->learner_type
      : LearnerType::tryFrom((string) $schoolEnrollment->learner_type) ?? LearnerType::Public;

    foreach ($courses as $course) {
      if (! $this->programProgression->isCourseAccessible($user, $course)) {
        continue;
      }

      $existing = Enrollment::query()
        ->where('course_id', $course->id)
        ->where('user_id', $user->id)
        ->first();

      if ($existing && $existing->status !== EnrollmentStatus::Cancelled) {
        continue;
      }

      try {
        $this->enrollmentService->enrollViaSchool($course, $user, $schoolEnrollment);
      } catch (BusinessException) {
        // Skip courses that cannot be enrolled (audience mismatch, etc.).
      }
    }
  }
}
