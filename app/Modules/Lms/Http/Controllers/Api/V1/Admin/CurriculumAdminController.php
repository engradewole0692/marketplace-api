<?php

declare(strict_types=1);

namespace App\Modules\Lms\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\ApiController;
use App\Modules\Lms\Http\Resources\EnrollmentResource;
use App\Modules\Lms\Http\Resources\LessonResource;
use App\Modules\Lms\Http\Resources\ModuleResource;
use App\Modules\Lms\Models\Course;
use App\Modules\Lms\Models\CourseModule;
use App\Modules\Lms\Models\Enrollment;
use App\Modules\Lms\Models\Lesson;
use App\Modules\Lms\Services\EnrollmentService;
use App\Modules\Lms\Services\LessonService;
use App\Modules\Lms\Services\ModuleService;
use App\Support\Api\PaginatedResponseBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class CurriculumAdminController extends ApiController
{
  private const LESSON_TYPES = [
    'video', 'text', 'quiz', 'assignment', 'resource', 'audio', 'document', 'slide',
    'external_url', 'zoom', 'youtube', 'private_youtube', 'playlist', 'vimeo', 'mixed', 'practical',
  ];

  private const VIDEO_SOURCES = [
    'youtube', 'private_youtube', 'playlist', 'media', 'embed', 'upload', 'vimeo', 'zoom', 'none',
  ];

  public function storeModule(Request $request, Course $course, ModuleService $service): JsonResponse
  {
    $this->authorize('update', $course);
    $validated = $request->validate([
      'title' => ['required', 'string', 'max:255'],
      'slug' => ['nullable', 'string', 'max:255'],
      'description' => ['nullable', 'string'],
      'sort_order' => ['nullable', 'integer', 'min:0'],
      'status' => ['nullable', 'string', Rule::in(['draft', 'published'])],
      'is_preview' => ['sometimes', 'boolean'],
      'duration_minutes' => ['nullable', 'integer', 'min:0'],
    ]);
    $module = $service->create($course, $validated, $request->user());

    return $this->responder->success(
      data: ['module' => new ModuleResource($module)],
      message: 'Module created.',
      status: 201,
    );
  }

  public function updateModule(Request $request, CourseModule $module, ModuleService $service): JsonResponse
  {
    $this->authorize('update', $module->course);
    $validated = $request->validate([
      'title' => ['sometimes', 'string', 'max:255'],
      'slug' => ['sometimes', 'nullable', 'string', 'max:255'],
      'description' => ['sometimes', 'nullable', 'string'],
      'sort_order' => ['sometimes', 'integer', 'min:0'],
      'status' => ['sometimes', 'string', Rule::in(['draft', 'published'])],
      'is_preview' => ['sometimes', 'boolean'],
      'duration_minutes' => ['sometimes', 'nullable', 'integer', 'min:0'],
    ]);
    $module = $service->update($module, $validated, $request->user());

    return $this->responder->success(
      data: ['module' => new ModuleResource($module)],
      message: 'Module updated.',
    );
  }

  public function reorderModules(Request $request, Course $course, ModuleService $service): JsonResponse
  {
    $this->authorize('update', $course);
    $validated = $request->validate([
      'items' => ['required', 'array'],
      'items.*.id' => ['required', 'uuid'],
      'items.*.sort_order' => ['required', 'integer', 'min:0'],
    ]);
    $service->reorder($course, $validated['items']);

    return $this->responder->success(message: 'Modules reordered.');
  }

  public function duplicateModule(CourseModule $module, ModuleService $service, Request $request): JsonResponse
  {
    $this->authorize('update', $module->course);
    $copy = $service->duplicate($module, $request->user());

    return $this->responder->success(
      data: ['module' => new ModuleResource($copy)],
      message: 'Module duplicated.',
      status: 201,
    );
  }

  public function destroyModule(CourseModule $module, ModuleService $service): JsonResponse
  {
    $this->authorize('update', $module->course);
    $service->delete($module);

    return $this->responder->success(message: 'Module deleted.');
  }

  public function storeLesson(Request $request, CourseModule $module, LessonService $service): JsonResponse
  {
    $this->authorize('update', $module->course);
    $validated = $request->validate([
      'title' => ['required', 'string', 'max:255'],
      'slug' => ['nullable', 'string', 'max:255'],
      'summary' => ['nullable', 'string'],
      'content' => ['nullable', 'string'],
      'sort_order' => ['nullable', 'integer', 'min:0'],
      'status' => ['nullable', 'string', Rule::in(['draft', 'published'])],
      'lesson_type' => ['nullable', 'string', Rule::in(self::LESSON_TYPES)],
      'is_preview' => ['sometimes', 'boolean'],
      'duration_minutes' => ['nullable', 'integer', 'min:0'],
      'video_source' => ['nullable', 'string', Rule::in(self::VIDEO_SOURCES)],
      'youtube_url' => ['nullable', 'string', 'max:500'],
      'youtube_video_id' => ['nullable', 'string', 'max:80'],
      'video_media_id' => ['nullable', 'uuid'],
      'embed_html' => ['nullable', 'string'],
      'is_mandatory' => ['sometimes', 'boolean'],
      'completion_threshold_percent' => ['nullable', 'integer', 'min:1', 'max:100'],
      'resources' => ['sometimes', 'array'],
    ]);
    $lesson = $service->create($module, $validated, $request->user());

    return $this->responder->success(
      data: ['lesson' => new LessonResource($lesson)],
      message: 'Lesson created.',
      status: 201,
    );
  }

  public function updateLesson(Request $request, Lesson $lesson, LessonService $service): JsonResponse
  {
    $this->authorize('update', $lesson->course);
    $validated = $request->validate([
      'title' => ['sometimes', 'string', 'max:255'],
      'slug' => ['sometimes', 'nullable', 'string', 'max:255'],
      'summary' => ['sometimes', 'nullable', 'string'],
      'content' => ['sometimes', 'nullable', 'string'],
      'sort_order' => ['sometimes', 'integer', 'min:0'],
      'status' => ['sometimes', 'string', Rule::in(['draft', 'published'])],
      'lesson_type' => ['sometimes', 'string', Rule::in(self::LESSON_TYPES)],
      'is_preview' => ['sometimes', 'boolean'],
      'duration_minutes' => ['sometimes', 'nullable', 'integer', 'min:0'],
      'video_source' => ['sometimes', 'string', Rule::in(self::VIDEO_SOURCES)],
      'youtube_url' => ['sometimes', 'nullable', 'string', 'max:500'],
      'youtube_video_id' => ['sometimes', 'nullable', 'string', 'max:80'],
      'video_media_id' => ['sometimes', 'nullable', 'uuid'],
      'embed_html' => ['sometimes', 'nullable', 'string'],
      'is_mandatory' => ['sometimes', 'boolean'],
      'completion_threshold_percent' => ['sometimes', 'integer', 'min:1', 'max:100'],
      'resources' => ['sometimes', 'array'],
    ]);
    $lesson = $service->update($lesson, $validated, $request->user());

    return $this->responder->success(
      data: ['lesson' => new LessonResource($lesson)],
      message: 'Lesson updated.',
    );
  }

  public function reorderLessons(Request $request, CourseModule $module, LessonService $service): JsonResponse
  {
    $this->authorize('update', $module->course);
    $validated = $request->validate([
      'items' => ['required', 'array'],
      'items.*.id' => ['required', 'uuid'],
      'items.*.sort_order' => ['required', 'integer', 'min:0'],
    ]);
    $service->reorder($module, $validated['items']);

    return $this->responder->success(message: 'Lessons reordered.');
  }

  public function duplicateLesson(Lesson $lesson, LessonService $service, Request $request): JsonResponse
  {
    $this->authorize('update', $lesson->course);
    $copy = $service->duplicate($lesson, $request->user());

    return $this->responder->success(
      data: ['lesson' => new LessonResource($copy)],
      message: 'Lesson duplicated.',
      status: 201,
    );
  }

  public function destroyLesson(Lesson $lesson, LessonService $service): JsonResponse
  {
    $this->authorize('update', $lesson->course);
    $service->delete($lesson);

    return $this->responder->success(message: 'Lesson deleted.');
  }

  public function enrollments(Request $request, EnrollmentService $service): JsonResponse
  {
    $this->authorize('viewAny', Enrollment::class);

    return $this->responder->success(
      data: PaginatedResponseBuilder::fromPaginator($service->paginate($request->query()), EnrollmentResource::class),
      message: 'Enrollments retrieved.',
    );
  }

  public function reports(): JsonResponse
  {
    $this->authorize('viewAny', Course::class);

    return $this->responder->success(
      data: [
        'courses_total' => Course::query()->count(),
        'courses_published' => Course::query()->where('status', 'published')->count(),
        'enrollments_total' => Enrollment::query()->count(),
        'enrollments_active' => Enrollment::query()->where('status', 'active')->count(),
        'enrollments_completed' => Enrollment::query()->where('status', 'completed')->count(),
        'certificates_issued' => \App\Modules\Lms\Models\CourseCertificate::query()->where('status', 'issued')->count(),
      ],
      message: 'LMS reports retrieved.',
    );
  }
}
