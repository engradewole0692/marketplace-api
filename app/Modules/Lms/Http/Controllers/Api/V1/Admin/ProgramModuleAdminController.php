<?php

declare(strict_types=1);

namespace App\Modules\Lms\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\ApiController;
use App\Modules\Lms\Http\Resources\CourseResource;
use App\Modules\Lms\Http\Resources\ProgramModuleResource;
use App\Modules\Lms\Models\Course;
use App\Modules\Lms\Models\CourseCategory;
use App\Modules\Lms\Models\LmsProgramModule;
use App\Modules\Lms\Models\LmsSchool;
use App\Modules\Lms\Services\ProgramModuleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class ProgramModuleAdminController extends ApiController
{
  public function indexForSchool(LmsSchool $school): JsonResponse
  {
    $this->authorize('view', $school);

    $modules = app(ProgramModuleService::class)->forSchool($school, false);

    return $this->responder->success(
      data: ['data' => ProgramModuleResource::collection($modules)],
      message: 'Program modules retrieved.',
    );
  }

  public function storeForSchool(Request $request, LmsSchool $school, ProgramModuleService $service): JsonResponse
  {
    $this->authorize('update', $school);

    $validated = $request->validate([
      'title' => ['required', 'string', 'max:255'],
      'slug' => ['nullable', 'string', 'max:255'],
      'description' => ['nullable', 'string'],
      'sort_order' => ['nullable', 'integer', 'min:0'],
      'status' => ['nullable', 'string', Rule::in(['draft', 'published', 'archived'])],
    ]);

    $module = $service->createForSchool($school, $validated);

    return $this->responder->success(
      data: ['program_module' => new ProgramModuleResource($module)],
      message: 'Program module created.',
      status: 201,
    );
  }

  public function indexForCategory(CourseCategory $category): JsonResponse
  {
    $this->authorize('view', $category);

    $modules = app(ProgramModuleService::class)->forCategory($category, false);

    return $this->responder->success(
      data: ['data' => ProgramModuleResource::collection($modules)],
      message: 'Program modules retrieved.',
    );
  }

  public function storeForCategory(Request $request, CourseCategory $category, ProgramModuleService $service): JsonResponse
  {
    $this->authorize('update', $category);

    $validated = $request->validate([
      'title' => ['required', 'string', 'max:255'],
      'slug' => ['nullable', 'string', 'max:255'],
      'description' => ['nullable', 'string'],
      'sort_order' => ['nullable', 'integer', 'min:0'],
      'status' => ['nullable', 'string', Rule::in(['draft', 'published', 'archived'])],
    ]);

    $module = $service->createForCategory($category, $validated);

    return $this->responder->success(
      data: ['program_module' => new ProgramModuleResource($module)],
      message: 'Program module created.',
      status: 201,
    );
  }

  public function update(Request $request, LmsProgramModule $programModule, ProgramModuleService $service): JsonResponse
  {
    $this->authorize('update', $programModule);

    $validated = $request->validate([
      'title' => ['sometimes', 'string', 'max:255'],
      'slug' => ['sometimes', 'nullable', 'string', 'max:255'],
      'description' => ['sometimes', 'nullable', 'string'],
      'sort_order' => ['sometimes', 'integer', 'min:0'],
      'status' => ['sometimes', 'string', Rule::in(['draft', 'published', 'archived'])],
    ]);

    $module = $service->update($programModule, $validated);

    return $this->responder->success(
      data: ['program_module' => new ProgramModuleResource($module)],
      message: 'Program module updated.',
    );
  }

  public function assignCourse(Request $request, LmsProgramModule $programModule, ProgramModuleService $service): JsonResponse
  {
    $this->authorize('update', $programModule);

    $validated = $request->validate([
      'course_id' => ['required', 'uuid'],
    ]);

    $course = Course::query()->where('uuid', $validated['course_id'])->firstOrFail();
    $course = $service->assignCourse($programModule, $course);

    return $this->responder->success(
      data: ['course' => new CourseResource($course->load(['programModule', 'school', 'category']))],
      message: 'Course assigned to program module.',
    );
  }

  public function unassignCourse(LmsProgramModule $programModule, Course $course, ProgramModuleService $service): JsonResponse
  {
    $this->authorize('update', $programModule);
    abort_unless($course->program_module_id === $programModule->id, 422, 'Course is not assigned to this module.');

    $course = $service->unassignCourse($course);

    return $this->responder->success(
      data: ['course' => new CourseResource($course)],
      message: 'Course removed from program module.',
    );
  }

  public function destroy(LmsProgramModule $programModule, ProgramModuleService $service): JsonResponse
  {
    $this->authorize('delete', $programModule);
    $service->delete($programModule);

    return $this->responder->success(message: 'Program module deleted.');
  }

  public function reorderSchoolModules(Request $request, LmsSchool $school, ProgramModuleService $service): JsonResponse
  {
    $this->authorize('update', $school);
    $validated = $request->validate([
      'module_ids' => ['required', 'array', 'min:1'],
      'module_ids.*' => ['required', 'uuid'],
    ]);
    $service->reorderSchoolModules($school, $validated['module_ids']);

    return $this->responder->success(message: 'Program modules reordered.');
  }

  public function reorderCategoryModules(Request $request, CourseCategory $category, ProgramModuleService $service): JsonResponse
  {
    $this->authorize('update', $category);
    $validated = $request->validate([
      'module_ids' => ['required', 'array', 'min:1'],
      'module_ids.*' => ['required', 'uuid'],
    ]);
    $service->reorderCategoryModules($category, $validated['module_ids']);

    return $this->responder->success(message: 'Program modules reordered.');
  }

  public function reorderCourses(Request $request, LmsProgramModule $programModule, ProgramModuleService $service): JsonResponse
  {
    $this->authorize('update', $programModule);
    $validated = $request->validate([
      'course_ids' => ['required', 'array', 'min:1'],
      'course_ids.*' => ['required', 'uuid'],
    ]);
    $service->reorderModuleCourses($programModule, $validated['course_ids']);

    return $this->responder->success(message: 'Courses reordered.');
  }
}
