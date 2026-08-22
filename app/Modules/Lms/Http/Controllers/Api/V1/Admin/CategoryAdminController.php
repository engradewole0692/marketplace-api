<?php

declare(strict_types=1);

namespace App\Modules\Lms\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\ApiController;
use App\Modules\Lms\Http\Resources\CourseCategoryResource;
use App\Modules\Lms\Models\CourseCategory;
use App\Modules\Lms\Services\CategoryService;
use App\Modules\Lms\Services\CurriculumIntegrityService;
use App\Support\Api\PaginatedResponseBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class CategoryAdminController extends ApiController
{
  public function index(Request $request, CategoryService $service): JsonResponse
  {
    $this->authorize('viewAny', CourseCategory::class);

    return $this->responder->success(
      data: PaginatedResponseBuilder::fromPaginator($service->paginate($request->query()), CourseCategoryResource::class),
      message: 'Categories retrieved.',
    );
  }

  public function store(Request $request, CategoryService $service): JsonResponse
  {
    $this->authorize('create', CourseCategory::class);
    $validated = $request->validate([
      'name' => ['required', 'string', 'max:255'],
      'slug' => ['nullable', 'string', 'max:255'],
      'description' => ['nullable', 'string'],
      'seo_title' => ['nullable', 'string', 'max:255'],
      'seo_description' => ['nullable', 'string'],
      'parent_id' => ['nullable', 'uuid'],
      'sort_order' => ['nullable', 'integer', 'min:0'],
      'status' => ['nullable', 'string', Rule::in(['active', 'inactive'])],
      'is_visible' => ['sometimes', 'boolean'],
      'is_free_learning_hub' => ['sometimes', 'boolean'],
      'icon' => ['nullable', 'string', 'max:80'],
      'cover_media_id' => ['nullable', 'uuid'],
    ]);
    $category = $service->create($validated, $request->user());

    return $this->responder->success(
      data: ['category' => new CourseCategoryResource($category->load('coverMedia'))],
      message: 'Category created.',
      status: 201,
    );
  }

  public function update(Request $request, CourseCategory $category, CategoryService $service): JsonResponse
  {
    $this->authorize('update', $category);
    $validated = $request->validate([
      'name' => ['sometimes', 'string', 'max:255'],
      'slug' => ['sometimes', 'nullable', 'string', 'max:255'],
      'description' => ['sometimes', 'nullable', 'string'],
      'seo_title' => ['sometimes', 'nullable', 'string', 'max:255'],
      'seo_description' => ['sometimes', 'nullable', 'string'],
      'parent_id' => ['sometimes', 'nullable', 'uuid'],
      'sort_order' => ['sometimes', 'integer', 'min:0'],
      'status' => ['sometimes', 'string', Rule::in(['active', 'inactive'])],
      'is_visible' => ['sometimes', 'boolean'],
      'icon' => ['sometimes', 'nullable', 'string', 'max:80'],
      'cover_media_id' => ['sometimes', 'nullable', 'uuid'],
    ]);
    $category = $service->update($category, $validated, $request->user());

    return $this->responder->success(
      data: ['category' => new CourseCategoryResource($category)],
      message: 'Category updated.',
    );
  }

  public function curriculumIntegrity(CourseCategory $category, CurriculumIntegrityService $integrity): JsonResponse
  {
    $this->authorize('view', $category);

    return $this->responder->success(
      data: ['curriculum_integrity' => $integrity->forCategory($category)],
      message: 'Category curriculum integrity retrieved.',
    );
  }

  public function destroy(CourseCategory $category, CategoryService $service): JsonResponse
  {
    $this->authorize('delete', $category);
    $service->delete($category);

    return $this->responder->success(message: 'Category deleted.');
  }
}
