<?php

declare(strict_types=1);

namespace App\Modules\Lms\Services;

use App\Contracts\ServiceContract;
use App\Enums\ApiErrorCode;
use App\Exceptions\BusinessException;
use App\Models\Member;
use App\Models\User;
use App\Modules\Lms\Enums\CourseAudience;
use App\Modules\Lms\Enums\CourseStatus;
use App\Modules\Lms\Enums\EnrollmentStatus;
use App\Modules\Lms\Enums\LearnerType;
use App\Modules\Lms\Models\Course;
use App\Modules\Lms\Models\CourseCoupon;
use App\Modules\Lms\Models\Enrollment;
use App\Modules\Lms\Models\SchoolEnrollment;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

final class EnrollmentService implements ServiceContract
{
  public function __construct(
    private readonly PricingEngine $pricingEngine,
    private readonly LmsAuditService $auditService,
    private readonly LmsNotificationService $notificationService,
    private readonly LmsAccessService $accessService,
    private readonly ProgramProgressionService $programProgression,
  ) {}

  /**
   * @param  array<string, mixed>  $filters
   */
  public function paginate(array $filters = []): LengthAwarePaginator
  {
    $query = Enrollment::query()
      ->with(['course.coverMedia', 'user', 'member'])
      ->latest('enrolled_at');

    if (! empty($filters['status'])) {
      $query->where('status', $filters['status']);
    }
    if (! empty($filters['course_id'])) {
      $courseId = Course::query()->where('uuid', $filters['course_id'])->value('id');
      if ($courseId) {
        $query->where('course_id', $courseId);
      }
    }
    if (! empty($filters['learner_type'])) {
      $query->where('learner_type', $filters['learner_type']);
    }
    if (! empty($filters['user_id'])) {
      $userId = User::query()->where('uuid', $filters['user_id'])->value('id');
      if ($userId) {
        $query->where('user_id', $userId);
      }
    }
    if (! empty($filters['search'])) {
      $search = (string) $filters['search'];
      $query->whereHas('user', function ($q) use ($search): void {
        $q->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%");
      });
    }

    return $query->paginate(min(100, max(1, (int) ($filters['per_page'] ?? 25))));
  }

  public function enroll(
    Course $course,
    User $user,
    LearnerType $learnerType,
    ?string $couponCode = null,
  ): Enrollment {
    if (! in_array($course->status, [CourseStatus::Published, CourseStatus::ComingSoon], true)) {
      throw new BusinessException('Course is not open for enrollment.', ApiErrorCode::UnprocessableEntity, null, 422);
    }

    $audience = $course->audience instanceof CourseAudience
      ? $course->audience
      : CourseAudience::tryFrom((string) ($course->audience ?? 'both')) ?? CourseAudience::Both;

    if ($learnerType === LearnerType::Member && ! $audience->allowsMember()) {
      throw new BusinessException('This course is for visitors only.', ApiErrorCode::Forbidden, null, 403);
    }
    if ($learnerType === LearnerType::Public && ! $audience->allowsVisitor()) {
      throw new BusinessException('This course is for approved members only.', ApiErrorCode::Forbidden, null, 403);
    }

    if ($course->school_id !== null && ! $this->accessService->bypassesPaidLmsAccess($user)) {
      $schoolAccess = SchoolEnrollment::query()
        ->where('school_id', $course->school_id)
        ->where('user_id', $user->id)
        ->whereIn('status', [
          EnrollmentStatus::Active->value,
          EnrollmentStatus::Completed->value,
        ])
        ->exists();

      if (! $schoolAccess) {
        throw new BusinessException(
          'Enroll in the school programme before accessing this course.',
          ApiErrorCode::Forbidden,
          null,
          403,
        );
      }
    }

    $this->programProgression->assertCourseAccessible($user, $course);

    $existing = Enrollment::query()
      ->where('course_id', $course->id)
      ->where('user_id', $user->id)
      ->first();

    if ($existing && $existing->status !== EnrollmentStatus::Cancelled) {
      return $existing->load(['course', 'user']);
    }

    return DB::transaction(function () use ($course, $user, $learnerType, $couponCode, $existing): Enrollment {
      $adminBypass = $this->accessService->bypassesPaidLmsAccess($user);
      $pricing = $adminBypass
        ? ['amount' => 0.0, 'currency' => $course->currency ?: 'USD', 'is_free' => true, 'list_price' => 0.0, 'promotional' => false, 'coupon_applied' => false, 'coupon_code' => null, 'audience' => 'both']
        : $this->pricingEngine->resolve($course, $learnerType, $couponCode);
      $member = Member::query()->where('user_id', $user->id)->first();

      if (! $adminBypass && $learnerType === LearnerType::Member && ($member === null || ! $member->qualifiesForMemberPricing())) {
        // Fall back to public learner pricing when membership is not approved.
        $learnerType = LearnerType::Public;
        $pricing = $this->pricingEngine->resolve($course, $learnerType, $couponCode);
      }

      $payload = [
        'course_id' => $course->id,
        'user_id' => $user->id,
        'member_id' => $member?->id,
        'learner_type' => $learnerType,
        'status' => $pricing['is_free'] || $pricing['amount'] <= 0
          ? EnrollmentStatus::Active
          : EnrollmentStatus::PendingPayment,
        'enrolled_at' => now(),
        'progress_percent' => 0,
        'price_paid' => $pricing['amount'],
        'currency' => $pricing['currency'],
        'coupon_code' => $pricing['coupon_code'],
        'metadata' => ['pricing' => $pricing],
      ];

      if ($existing) {
        $existing->fill($payload)->save();
        $enrollment = $existing;
      } else {
        $enrollment = Enrollment::query()->create($payload);
      }

      if ($pricing['coupon_applied'] && $pricing['coupon_code'] && ($pricing['is_free'] || $pricing['amount'] <= 0)) {
        CourseCoupon::query()
          ->where('code', $pricing['coupon_code'])
          ->increment('redeemed_count');
      }

      if ($enrollment->status === EnrollmentStatus::Active) {
        $course->increment('enrollment_count');
      }

      $this->auditService->record($course, $user, 'enrollment.created', 'Learner enrolled.', null, [
        'enrollment_uuid' => $enrollment->uuid,
        'learner_type' => $learnerType->value,
      ]);

      $fresh = $enrollment->fresh(['course', 'user', 'member']);
      $this->notificationService->notifyEnrollment($fresh);

      return $fresh;
    });
  }

  public function cancel(Enrollment $enrollment, User $actor): Enrollment
  {
    $enrollment->forceFill([
      'status' => EnrollmentStatus::Cancelled,
      'cancelled_at' => now(),
    ])->save();
    $this->auditService->record($enrollment->course, $actor, 'enrollment.cancelled', 'Enrollment cancelled.');

    return $enrollment->fresh(['course', 'user']);
  }

  public function lock(Enrollment $enrollment, User $actor, ?string $reason = null): Enrollment
  {
    $enrollment->forceFill([
      'status' => EnrollmentStatus::Locked,
      'locked_at' => now(),
      'metadata' => array_merge($enrollment->metadata ?? [], ['lock_reason' => $reason]),
    ])->save();
    $this->auditService->record($enrollment->course, $actor, 'enrollment.locked', $reason ?? 'Enrollment locked.');

    return $enrollment->fresh(['course', 'user']);
  }

  public function restart(Enrollment $enrollment, User $actor): Enrollment
  {
    if (! in_array($enrollment->status, [
      EnrollmentStatus::Completed,
      EnrollmentStatus::Expired,
      EnrollmentStatus::Cancelled,
      EnrollmentStatus::Locked,
    ], true)) {
      throw new BusinessException('Only completed, expired, cancelled, or locked enrollments can be restarted.', ApiErrorCode::UnprocessableEntity, null, 422);
    }

    return DB::transaction(function () use ($enrollment, $actor): Enrollment {
      $enrollment->lessonProgress()->delete();
      $enrollment->forceFill([
        'status' => EnrollmentStatus::Active,
        'progress_percent' => 0,
        'completed_at' => null,
        'expired_at' => null,
        'cancelled_at' => null,
        'locked_at' => null,
        'restarted_at' => now(),
        'last_accessed_at' => now(),
      ])->save();

      $this->auditService->record($enrollment->course, $actor, 'enrollment.restarted', 'Enrollment restarted.');

      return $enrollment->fresh(['course', 'user']);
    });
  }

  public function markCompleted(Enrollment $enrollment): Enrollment
  {
    $enrollment->forceFill([
      'status' => EnrollmentStatus::Completed,
      'completed_at' => now(),
      'progress_percent' => 100,
    ])->save();

    $fresh = $enrollment->fresh(['course', 'certificate', 'user']);
    $this->notificationService->notifyCourseCompletion($fresh);

    return $fresh;
  }

  /**
   * Enroll a learner in a school course without separate course payment.
   * Requires an active/completed school enrollment covering the course.
   */
  public function enrollViaSchool(Course $course, User $user, SchoolEnrollment $schoolEnrollment): Enrollment
  {
    if ($course->school_id === null || $course->school_id !== $schoolEnrollment->school_id) {
      throw new BusinessException('Course is not part of this school programme.', ApiErrorCode::Forbidden, null, 403);
    }

    $schoolStatus = $schoolEnrollment->status instanceof EnrollmentStatus
      ? $schoolEnrollment->status
      : EnrollmentStatus::tryFrom((string) $schoolEnrollment->status);

    if ($schoolStatus !== EnrollmentStatus::Active && $schoolStatus !== EnrollmentStatus::Completed) {
      throw new BusinessException('School enrollment is not active.', ApiErrorCode::Forbidden, null, 403);
    }

    $this->programProgression->assertCourseAccessible($user, $course);

    $existing = Enrollment::query()
      ->where('course_id', $course->id)
      ->where('user_id', $user->id)
      ->first();

    if ($existing && $existing->status !== EnrollmentStatus::Cancelled) {
      return $existing->load(['course', 'user']);
    }

    $member = Member::query()->where('user_id', $user->id)->first();
    $learnerType = $schoolEnrollment->learner_type instanceof LearnerType
      ? $schoolEnrollment->learner_type
      : LearnerType::tryFrom((string) $schoolEnrollment->learner_type) ?? LearnerType::Public;

    $payload = [
      'course_id' => $course->id,
      'user_id' => $user->id,
      'member_id' => $member?->id,
      'learner_type' => $learnerType,
      'status' => EnrollmentStatus::Active,
      'enrolled_at' => now(),
      'progress_percent' => 0,
      'price_paid' => 0,
      'currency' => $schoolEnrollment->currency ?: 'USD',
      'metadata' => [
        'via_school_enrollment' => $schoolEnrollment->uuid,
        'school_uuid' => $schoolEnrollment->school?->uuid,
      ],
    ];

    if ($existing) {
      $existing->fill($payload)->save();

      return $existing->fresh(['course', 'user']);
    }

    return Enrollment::query()->create($payload)->load(['course', 'user']);
  }

  /**
   * @param  array<string, mixed>  $filters
   */
  public function paginateForUser(User $user, array $filters = []): LengthAwarePaginator
  {
    return Enrollment::query()
      ->where('user_id', $user->id)
      ->with(['course.coverMedia', 'course.instructors', 'certificate'])
      ->when(! empty($filters['status']), fn ($q) => $q->where('status', $filters['status']))
      ->latest('enrolled_at')
      ->paginate(min(100, max(1, (int) ($filters['per_page'] ?? 25))));
  }
}
