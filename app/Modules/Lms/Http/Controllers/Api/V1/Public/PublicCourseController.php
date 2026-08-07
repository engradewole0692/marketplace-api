<?php

declare(strict_types=1);

namespace App\Modules\Lms\Http\Controllers\Api\V1\Public;

use App\Http\Controllers\Api\V1\ApiController;
use App\Modules\Lms\Enums\LearnerType;
use App\Modules\Lms\Http\Resources\CourseResource;
use App\Modules\Lms\Http\Resources\EnrollmentResource;
use App\Modules\Lms\Models\Course;
use App\Modules\Lms\Services\CourseService;
use App\Modules\Lms\Services\EnrollmentService;
use App\Modules\Lms\Services\ProgressService;
use App\Support\Api\PaginatedResponseBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class PublicCourseController extends ApiController
{
  public function index(Request $request, CourseService $service): JsonResponse
  {
    $memberMinistryIds = [];
    $user = $request->user('sanctum');
    if ($user) {
      $member = \App\Models\Member::query()->where('user_id', $user->id)->first();
      if ($member) {
        $memberMinistryIds = array_values(array_filter([
          $member->ministry_id,
          $member->preferred_ministry_id,
          ...$member->ministryAssignments()->pluck('ministry_id')->all(),
        ]));
      }
    }

    return $this->responder->success(
      data: PaginatedResponseBuilder::fromPaginator(
        $service->paginateVisible($request->query(), $memberMinistryIds),
        CourseResource::class,
      ),
      message: 'Courses retrieved.',
    );
  }

  public function show(string $slug, CourseService $service): JsonResponse
  {
    $course = $service->findPublicBySlug($slug);
    if ($course === null) {
      abort(404, 'Course not found.');
    }

    return $this->responder->success(
      data: ['course' => new CourseResource($course)],
      message: 'Course retrieved.',
    );
  }

  public function enroll(Request $request, string $slug, CourseService $courses, EnrollmentService $enrollments): JsonResponse
  {
    $this->authorize('create', \App\Modules\Lms\Models\Enrollment::class);

    $course = $courses->findPublicBySlug($slug);
    if ($course === null || $course->status->value === 'coming_soon') {
      abort(404, 'Course not available for enrollment.');
    }

    $user = $request->user();
    $hasMember = \App\Models\Member::query()->where('user_id', $user->id)->exists();
    $learnerType = $hasMember ? LearnerType::Member : LearnerType::Public;

    $validated = $request->validate([
      'coupon_code' => ['nullable', 'string', 'max:80'],
    ]);

    $enrollment = $enrollments->enroll($course, $user, $learnerType, $validated['coupon_code'] ?? null);

    $status = $enrollment->status instanceof \BackedEnum ? $enrollment->status->value : (string) $enrollment->status;

    return $this->responder->success(
      data: [
        'enrollment' => new EnrollmentResource($enrollment),
        'requires_payment' => $status === 'pending_payment',
      ],
      message: $status === 'pending_payment'
        ? 'Enrollment created. Complete payment to unlock the course.'
        : 'Enrolled successfully.',
      status: 201,
    );
  }

  public function verifyCertificate(string $code, ProgressService $progress): JsonResponse
  {
    $certificate = $progress->verify($code);
    if ($certificate === null) {
      abort(404, 'Certificate not found.');
    }

    return $this->responder->success(
      data: [
        'certificate' => [
          'type' => 'course',
          'certificate_number' => $certificate->certificate_number,
          'verification_code' => $certificate->verification_code,
          'status' => $certificate->status instanceof \BackedEnum ? $certificate->status->value : $certificate->status,
          'issued_at' => $certificate->issued_at?->toIso8601String(),
          'course' => [
            'id' => $certificate->course?->uuid,
            'title' => $certificate->course?->title,
            'slug' => $certificate->course?->slug,
          ],
          'recipient' => [
            'name' => $certificate->user?->name,
          ],
          'learner' => [
            'name' => $certificate->user?->name,
          ],
          'certificate_url' => $certificate->certificateMedia?->url(),
          'verification_url' => url('/certificate/'.$certificate->verification_code),
        ],
      ],
      message: 'Certificate verified.',
    );
  }
}
