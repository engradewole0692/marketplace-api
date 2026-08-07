<?php

declare(strict_types=1);

namespace App\Modules\Lms\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\ApiController;
use App\Modules\Lms\Http\Resources\InstructorResource;
use App\Modules\Lms\Models\Instructor;
use App\Modules\Lms\Services\InstructorService;
use App\Support\Api\PaginatedResponseBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class InstructorAdminController extends ApiController
{
  public function index(Request $request, InstructorService $service): JsonResponse
  {
    $this->authorize('viewAny', Instructor::class);

    return $this->responder->success(
      data: PaginatedResponseBuilder::fromPaginator($service->paginate($request->query()), InstructorResource::class),
      message: 'Instructors retrieved.',
    );
  }

  public function store(Request $request, InstructorService $service): JsonResponse
  {
    $this->authorize('create', Instructor::class);
    $validated = $request->validate([
      'name' => ['required', 'string', 'max:255'],
      'slug' => ['nullable', 'string', 'max:255'],
      'title' => ['nullable', 'string', 'max:255'],
      'bio' => ['nullable', 'string'],
      'email' => ['nullable', 'email', 'max:255'],
      'website_url' => ['nullable', 'string', 'max:500'],
      'photo_media_id' => ['nullable', 'uuid'],
      'user_id' => ['nullable', 'uuid'],
      'status' => ['nullable', 'string', Rule::in(['active', 'inactive'])],
      'metadata' => ['nullable', 'array'],
      'metadata.phone' => ['nullable', 'string', 'max:40'],
      'metadata.ministry' => ['nullable', 'string', 'max:255'],
      'metadata.social_links' => ['nullable', 'array'],
      'metadata.experience' => ['nullable', 'string'],
    ]);
    $instructor = $service->create($validated, $request->user());

    return $this->responder->success(
      data: ['instructor' => new InstructorResource($instructor->load('photoMedia'))],
      message: 'Instructor created.',
      status: 201,
    );
  }

  public function update(Request $request, Instructor $instructor, InstructorService $service): JsonResponse
  {
    $this->authorize('update', $instructor);
    $validated = $request->validate([
      'name' => ['sometimes', 'string', 'max:255'],
      'slug' => ['sometimes', 'nullable', 'string', 'max:255'],
      'title' => ['sometimes', 'nullable', 'string', 'max:255'],
      'bio' => ['sometimes', 'nullable', 'string'],
      'email' => ['sometimes', 'nullable', 'email', 'max:255'],
      'website_url' => ['sometimes', 'nullable', 'string', 'max:500'],
      'photo_media_id' => ['sometimes', 'nullable', 'uuid'],
      'user_id' => ['sometimes', 'nullable', 'uuid'],
      'status' => ['sometimes', 'string', Rule::in(['active', 'inactive'])],
      'metadata' => ['sometimes', 'nullable', 'array'],
      'metadata.phone' => ['nullable', 'string', 'max:40'],
      'metadata.ministry' => ['nullable', 'string', 'max:255'],
      'metadata.social_links' => ['nullable', 'array'],
      'metadata.experience' => ['nullable', 'string'],
    ]);
    $instructor = $service->update($instructor, $validated, $request->user());

    return $this->responder->success(
      data: ['instructor' => new InstructorResource($instructor)],
      message: 'Instructor updated.',
    );
  }

  public function destroy(Instructor $instructor, InstructorService $service): JsonResponse
  {
    $this->authorize('delete', $instructor);
    $service->delete($instructor);

    return $this->responder->success(message: 'Instructor deleted.');
  }
}
