<?php

declare(strict_types=1);

namespace App\Modules\Lms\Http\Controllers\Api\V1\Public;

use App\Http\Controllers\Api\V1\ApiController;
use App\Models\User;
use App\Modules\Lms\Enums\EnrollmentStatus;
use App\Modules\Lms\Enums\LearnerType;
use App\Modules\Lms\Http\Resources\SchoolEnrollmentResource;
use App\Modules\Lms\Http\Resources\SchoolResource;
use App\Modules\Lms\Models\LmsSchool;
use App\Modules\Lms\Models\SchoolEnrollment;
use App\Modules\Lms\Services\LearnerTypeResolver;
use App\Modules\Lms\Services\PricingEngine;
use App\Modules\Lms\Services\SchoolCommerceService;
use App\Modules\Lms\Services\SchoolEnrollmentService;
use App\Modules\Lms\Services\SchoolService;
use App\Support\Api\PaginatedResponseBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class PublicSchoolController extends ApiController
{
  public function index(Request $request, SchoolService $service): JsonResponse
  {
    $paginator = $service->paginatePublished($request->query());
    $user = $request->user('sanctum');
    $learnerType = $this->viewerType($user);
    $paginator->getCollection()->each(fn (LmsSchool $school) => $this->attachViewerPricing($school, $learnerType, $user !== null));

    if ($user) {
      $enrollments = SchoolEnrollment::query()
        ->where('user_id', $user->id)
        ->whereIn('school_id', $paginator->pluck('id'))
        ->get()
        ->keyBy('school_id');

      $paginator->getCollection()->transform(function (LmsSchool $school) use ($enrollments): LmsSchool {
        $school->user_enrollment = $enrollments->get($school->id);

        return $school;
      });
    }

    return $this->responder->success(
      data: PaginatedResponseBuilder::fromPaginator($paginator, SchoolResource::class),
      message: 'Schools retrieved.',
    );
  }

  public function show(string $slug, Request $request, SchoolService $service): JsonResponse
  {
    $school = $service->findPublicBySlug($slug);
    if ($school === null) {
      abort(404, 'School not found.');
    }

    $user = $request->user('sanctum');
    $this->attachViewerPricing($school, $this->viewerType($user), $user !== null);
    if ($user) {
      $school->user_enrollment = SchoolEnrollment::query()
        ->where('school_id', $school->id)
        ->where('user_id', $user->id)
        ->first();
    }

    return $this->responder->success(
      data: ['school' => new SchoolResource($school)],
      message: 'School retrieved.',
    );
  }

  public function enroll(string $slug, Request $request, SchoolService $schoolService, SchoolEnrollmentService $enrollmentService): JsonResponse
  {
    $school = $schoolService->findPublicBySlug($slug);
    if ($school === null) {
      abort(404, 'School not found.');
    }

    $user = $request->user();
    abort_unless($user !== null, 401);

    $learnerType = app(LearnerTypeResolver::class)->resolve($user);

    $enrollment = $enrollmentService->enroll($school, $user, $learnerType);
    $pricing = $enrollment->status === EnrollmentStatus::PendingPayment
      ? app(SchoolCommerceService::class)->refreshPricing($enrollment)
      : null;

    return $this->responder->success(
      data: [
        'enrollment' => new SchoolEnrollmentResource($enrollment->fresh(['school', 'user']) ?? $enrollment),
        'pricing' => $pricing,
        'requires_payment' => $enrollment->status instanceof \BackedEnum
          ? $enrollment->status->value === 'pending_payment'
          : (string) $enrollment->status === 'pending_payment',
      ],
      message: 'School enrollment created.',
      status: 201,
    );
  }

  private function viewerType(?User $user): LearnerType
  {
    return $user !== null ? app(LearnerTypeResolver::class)->resolve($user) : LearnerType::Public;
  }

  /** Payable price for the current viewer, resolved from verified membership state on every request. */
  private function attachViewerPricing(LmsSchool $school, LearnerType $learnerType, bool $authenticated): void
  {
    $school->viewer_pricing = [
      ...app(PricingEngine::class)->resolveSchool($school, $learnerType),
      'eligible_for_member_price' => $learnerType === LearnerType::Member,
      'authenticated' => $authenticated,
    ];
  }
}
