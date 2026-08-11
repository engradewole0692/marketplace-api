<?php

declare(strict_types=1);

namespace App\Modules\Lms\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\ApiController;
use App\Modules\Lms\Http\Resources\SchoolEnrollmentResource;
use App\Modules\Lms\Http\Resources\SchoolResource;
use App\Modules\Lms\Models\LmsSchool;
use App\Modules\Lms\Models\SchoolEnrollment;
use App\Modules\Lms\Services\SchoolEnrollmentService;
use App\Modules\Lms\Services\SchoolService;
use App\Support\Api\PaginatedResponseBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class SchoolAdminController extends ApiController
{
  public function index(Request $request, SchoolService $service): JsonResponse
  {
    $this->authorize('viewAny', LmsSchool::class);

    return $this->responder->success(
      data: PaginatedResponseBuilder::fromPaginator($service->paginate($request->query()), SchoolResource::class),
      message: 'Schools retrieved.',
    );
  }

  public function store(Request $request, SchoolService $service): JsonResponse
  {
    $this->authorize('create', LmsSchool::class);
    $validated = $request->validate([
      'title' => ['required', 'string', 'max:255'],
      'slug' => ['nullable', 'string', 'max:255'],
      'subtitle' => ['nullable', 'string', 'max:255'],
      'summary' => ['nullable', 'string'],
      'description' => ['nullable', 'string'],
      'status' => ['nullable', 'string', Rule::in(['draft', 'published', 'archived', 'coming_soon'])],
      'sort_order' => ['nullable', 'integer', 'min:0'],
      'member_price' => ['nullable', 'numeric', 'min:0'],
      'public_price' => ['nullable', 'numeric', 'min:0'],
      'currency' => ['nullable', 'string', 'max:8'],
      'certificate_enabled' => ['sometimes', 'boolean'],
      'sequential_progression' => ['sometimes', 'boolean'],
      'cover_media_id' => ['nullable', 'uuid'],
      'thumbnail_media_id' => ['nullable', 'uuid'],
      'metadata' => ['nullable', 'array'],
    ]);

    $school = $service->create($validated, $request->user());

    return $this->responder->success(
      data: ['school' => new SchoolResource($school->load(['coverMedia', 'thumbnailMedia']))],
      message: 'School created.',
      status: 201,
    );
  }

  public function show(LmsSchool $school): JsonResponse
  {
    $this->authorize('view', $school);

    return $this->responder->success(
      data: ['school' => new SchoolResource($school->load([
        'coverMedia',
        'thumbnailMedia',
        'programModules.courses',
        'courses',
      ])->loadCount('courses'))],
      message: 'School retrieved.',
    );
  }

  public function update(Request $request, LmsSchool $school, SchoolService $service): JsonResponse
  {
    $this->authorize('update', $school);
    $validated = $request->validate([
      'title' => ['sometimes', 'string', 'max:255'],
      'slug' => ['sometimes', 'nullable', 'string', 'max:255'],
      'subtitle' => ['sometimes', 'nullable', 'string', 'max:255'],
      'summary' => ['sometimes', 'nullable', 'string'],
      'description' => ['sometimes', 'nullable', 'string'],
      'status' => ['sometimes', 'string', Rule::in(['draft', 'published', 'archived', 'coming_soon'])],
      'sort_order' => ['sometimes', 'integer', 'min:0'],
      'member_price' => ['sometimes', 'numeric', 'min:0'],
      'public_price' => ['sometimes', 'numeric', 'min:0'],
      'currency' => ['sometimes', 'string', 'max:8'],
      'certificate_enabled' => ['sometimes', 'boolean'],
      'sequential_progression' => ['sometimes', 'boolean'],
      'cover_media_id' => ['sometimes', 'nullable', 'uuid'],
      'thumbnail_media_id' => ['sometimes', 'nullable', 'uuid'],
      'metadata' => ['sometimes', 'nullable', 'array'],
    ]);

    $school = $service->update($school, $validated, $request->user());

    return $this->responder->success(
      data: ['school' => new SchoolResource($school)],
      message: 'School updated.',
    );
  }

  public function publish(LmsSchool $school, Request $request, SchoolService $service): JsonResponse
  {
    $this->authorize('publish', $school);

    return $this->responder->success(
      data: ['school' => new SchoolResource($service->publish($school, $request->user()))],
      message: 'School published.',
    );
  }

  public function unpublish(LmsSchool $school, Request $request, SchoolService $service): JsonResponse
  {
    $this->authorize('publish', $school);

    return $this->responder->success(
      data: ['school' => new SchoolResource($service->unpublish($school, $request->user()))],
      message: 'School unpublished.',
    );
  }

  public function archive(LmsSchool $school, Request $request, SchoolService $service): JsonResponse
  {
    $this->authorize('delete', $school);

    return $this->responder->success(
      data: ['school' => new SchoolResource($service->archive($school, $request->user()))],
      message: 'School archived.',
    );
  }

  public function destroy(LmsSchool $school, SchoolService $service): JsonResponse
  {
    $this->authorize('delete', $school);
    $service->delete($school);

    return $this->responder->success(message: 'School deleted.');
  }
}
