<?php

declare(strict_types=1);

namespace App\Modules\Lms\Http\Controllers\Api\V1\Public;

use App\Http\Controllers\Api\V1\ApiController;
use App\Modules\Lms\Http\Resources\SchoolEnrollmentResource;
use App\Modules\Lms\Http\Resources\SchoolResource;
use App\Modules\Lms\Models\LmsSchool;
use App\Modules\Lms\Models\SchoolEnrollment;
use App\Modules\Lms\Services\LearnerTypeResolver;
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

    return $this->responder->success(
      data: [
        'enrollment' => new SchoolEnrollmentResource($enrollment),
        'requires_payment' => $enrollment->status instanceof \BackedEnum
          ? $enrollment->status->value === 'pending_payment'
          : (string) $enrollment->status === 'pending_payment',
      ],
      message: 'School enrollment created.',
      status: 201,
    );
  }
}
