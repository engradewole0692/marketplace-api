<?php

declare(strict_types=1);

namespace App\Modules\Lms\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\ApiController;
use App\Modules\Lms\Http\Resources\CourseResource;
use App\Modules\Lms\Models\Course;
use App\Modules\Lms\Services\CourseMigrationService;
use App\Modules\Lms\Services\CourseMigrationVerificationService;
use App\Modules\Lms\Services\CourseService;
use App\Modules\Lms\Services\PrayerTrainingImportService;
use App\Modules\Lms\Services\YoutubeMetadataService;
use App\Support\Api\PaginatedResponseBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class CourseAdminController extends ApiController
{
  public function index(Request $request, CourseService $service): JsonResponse
  {
    $this->authorize('viewAny', Course::class);

    return $this->responder->success(
      data: PaginatedResponseBuilder::fromPaginator($service->paginate($request->query()), CourseResource::class),
      message: 'Courses retrieved.',
    );
  }

  public function show(Course $course): JsonResponse
  {
    $this->authorize('view', $course);
    $course->load([
      'category', 'subcategory', 'level', 'language', 'coverMedia', 'bannerMedia', 'trailerMedia',
      'tags', 'instructors.photoMedia', 'modules.lessons.resources', 'faqs', 'downloads',
      'ministries', 'primaryMinistry', 'certificateTemplate', 'school', 'programModule',
    ]);
    $course->loadCount(['enrollments', 'modules', 'lessons']);

    return $this->responder->success(
      data: ['course' => new CourseResource($course)],
      message: 'Course retrieved.',
    );
  }

  public function store(Request $request, CourseService $service): JsonResponse
  {
    $this->authorize('create', Course::class);
    $course = $service->create($this->validated($request), $request->user());

    return $this->responder->success(
      data: ['course' => new CourseResource($course)],
      message: 'Course created.',
      status: 201,
    );
  }

  public function update(Request $request, Course $course, CourseService $service): JsonResponse
  {
    $this->authorize('update', $course);
    $course = $service->update($course, $this->validated($request, true), $request->user());

    return $this->responder->success(
      data: ['course' => new CourseResource($course)],
      message: 'Course updated.',
    );
  }

  public function publish(Course $course, CourseService $service, Request $request): JsonResponse
  {
    $this->authorize('publish', $course);
    $course = $service->publish($course, $request->user());

    return $this->responder->success(
      data: ['course' => new CourseResource($course)],
      message: 'Course published.',
    );
  }

  public function unpublish(Course $course, CourseService $service, Request $request): JsonResponse
  {
    $this->authorize('update', $course);
    $course = $service->unpublish($course, $request->user());

    return $this->responder->success(
      data: ['course' => new CourseResource($course)],
      message: 'Course unpublished.',
    );
  }

  public function archive(Course $course, CourseService $service, Request $request): JsonResponse
  {
    $this->authorize('update', $course);
    $course = $service->archive($course, $request->user());

    return $this->responder->success(
      data: ['course' => new CourseResource($course)],
      message: 'Course archived.',
    );
  }

  public function duplicate(Course $course, CourseService $service, Request $request): JsonResponse
  {
    $this->authorize('create', Course::class);
    $copy = $service->duplicate($course, $request->user());

    return $this->responder->success(
      data: ['course' => new CourseResource($copy)],
      message: 'Course duplicated.',
      status: 201,
    );
  }

  public function clone(Course $course, CourseService $service, Request $request): JsonResponse
  {
    return $this->duplicate($course, $service, $request);
  }

  public function schedule(Request $request, Course $course, CourseService $service): JsonResponse
  {
    $this->authorize('publish', $course);
    $validated = $request->validate([
      'scheduled_publish_at' => ['required', 'date', 'after:now'],
    ]);
    $course = $service->schedulePublish($course, $validated['scheduled_publish_at'], $request->user());

    return $this->responder->success(
      data: ['course' => new CourseResource($course)],
      message: 'Course scheduled for publishing.',
    );
  }

  public function destroy(Course $course, CourseService $service, Request $request): JsonResponse
  {
    $this->authorize('delete', $course);
    $service->delete($course, $request->user());

    return $this->responder->success(message: 'Course deleted.');
  }

  public function resolveYoutube(Request $request, YoutubeMetadataService $youtube): JsonResponse
  {
    $this->authorize('viewAny', Course::class);
    $validated = $request->validate([
      'url' => ['required', 'string', 'max:1000'],
    ]);

    return $this->responder->success(
      data: ['youtube' => $youtube->resolve($validated['url'])],
      message: 'YouTube metadata resolved.',
    );
  }

  public function importSchema(): JsonResponse
  {
    $this->authorize('viewAny', Course::class);

    return $this->responder->success(
      data: [
        'importer' => [
          'name' => 'TribesHub / Legacy Course Importer',
          'status' => 'prepared',
          'command' => 'lms:migrate-legacy-courses',
          'verify_command' => 'lms:verify-migrated-courses',
          'supported_entities' => [
            'course', 'instructor', 'modules', 'lessons', 'youtube_videos',
            'resources', 'pricing', 'assessments', 'categories',
          ],
          'notes' => [
            'Does not migrate TribesHub yet — interfaces and legacy catalog importer are ready.',
            'Media Library is reused; videos remain on YouTube.',
            'Existing courses matched by slug are never rebuilt.',
          ],
        ],
      ],
      message: 'Import interfaces retrieved.',
    );
  }

  public function importDryRun(CourseMigrationService $migration): JsonResponse
  {
    $this->authorize('create', Course::class);
    $result = $migration->migrate(true);

    return $this->responder->success(
      data: ['dry_run' => $result],
      message: 'Import dry-run completed.',
    );
  }

  public function importVerify(CourseMigrationVerificationService $verification): JsonResponse
  {
    $this->authorize('viewAny', Course::class);

    return $this->responder->success(
      data: ['verification' => $verification->verify()],
      message: 'Import verification completed.',
    );
  }

  public function importRun(CourseMigrationService $migration): JsonResponse
  {
    $this->authorize('create', Course::class);
    $result = $migration->migrate(false);

    return $this->responder->success(
      data: ['import' => $result],
      message: 'Legacy course import completed.',
    );
  }

  public function importPrayerTrainingSchema(): JsonResponse
  {
    $this->authorize('viewAny', Course::class);

    return $this->responder->success(
      data: [
        'importer' => [
          'name' => 'Prayer Training Timetable Importer',
          'course_slug' => PrayerTrainingImportService::COURSE_SLUG,
          'command' => 'lms:import-prayer-training',
          'default_path' => 'database/imports/Prayer Training.xlsx',
          'expected_columns' => [
            'Timetable: Title (column A), YouTube URL (column B), blank rows = module breaks',
            'Tabular: Week/Module, Lesson #, Title, YouTube URL',
          ],
          'notes' => [
            'Preserves lesson order, titles, and YouTube URLs from the spreadsheet.',
            'Groups timetable rows into generic modules (not mandatory weekly scheduling).',
            'Exam/Exams rows become a draft assessment placeholder without fabricated questions.',
            'School/Ministry assignment remains unassigned until admin review.',
            'Idempotent — safe to re-run without duplicating lessons.',
          ],
        ],
      ],
      message: 'Prayer Training import schema retrieved.',
    );
  }

  public function importPrayerTrainingDryRun(Request $request, PrayerTrainingImportService $import): JsonResponse
  {
    $this->authorize('create', Course::class);
    $request->validate(['file' => ['required', 'file', 'mimes:xlsx,xls,csv']]);

    $result = $import->importFromUpload($request->file('file'), true, $request->user());

    return $this->responder->success(
      data: ['dry_run' => $result],
      message: 'Prayer Training import dry-run completed.',
    );
  }

  public function importPrayerTrainingRun(Request $request, PrayerTrainingImportService $import): JsonResponse
  {
    $this->authorize('create', Course::class);
    $request->validate(['file' => ['required', 'file', 'mimes:xlsx,xls,csv']]);

    $result = $import->importFromUpload($request->file('file'), false, $request->user());

    return $this->responder->success(
      data: ['import' => $result],
      message: 'Prayer Training import completed.',
    );
  }

  /** @return array<string, mixed> */
  private function validated(Request $request, bool $partial = false): array
  {
    $sometimes = $partial ? 'sometimes' : 'nullable';

    return $request->validate([
      'title' => [$partial ? 'sometimes' : 'required', 'string', 'max:255'],
      'slug' => [$sometimes, 'string', 'max:255'],
      'subtitle' => [$sometimes, 'string', 'max:255'],
      'summary' => [$sometimes, 'string'],
      'description' => [$sometimes, 'string'],
      'status' => [$sometimes, 'string', Rule::in(['draft', 'published', 'archived', 'coming_soon', 'hidden'])],
      'access_scope' => [$sometimes, 'string', Rule::in(['general', 'ministry'])],
      'audience' => [$sometimes, 'string', Rule::in(['visitor_only', 'member_only', 'both'])],
      'difficulty' => [$sometimes, 'string', Rule::in(['beginner', 'intermediate', 'advanced', 'expert'])],
      'category_id' => [$sometimes, 'uuid'],
      'subcategory_id' => [$sometimes, 'uuid'],
      'level_id' => [$sometimes, 'uuid'],
      'language_id' => [$sometimes, 'uuid'],
      'primary_ministry_id' => [$sometimes, 'uuid'],
      'school_id' => [$sometimes, 'uuid'],
      'program_module_id' => [$sometimes, 'nullable', 'uuid'],
      'ministry_ids' => [$sometimes, 'array'],
      'ministry_ids.*' => ['uuid'],
      'is_featured' => ['sometimes', 'boolean'],
      'is_popular' => ['sometimes', 'boolean'],
      'is_recommended' => ['sometimes', 'boolean'],
      'sort_order' => [$sometimes, 'integer', 'min:0'],
      'is_free' => ['sometimes', 'boolean'],
      'visitor_free' => ['sometimes', 'boolean'],
      'member_free' => ['sometimes', 'boolean'],
      'member_price' => [$sometimes, 'numeric', 'min:0'],
      'public_price' => [$sometimes, 'numeric', 'min:0'],
      'promotional_price' => [$sometimes, 'numeric', 'min:0'],
      'promotional_starts_at' => [$sometimes, 'date'],
      'promotional_ends_at' => [$sometimes, 'date'],
      'currency' => [$sometimes, 'string', 'size:3'],
      'cover_media_id' => [$sometimes, 'uuid'],
      'thumbnail_media_id' => [$sometimes, 'uuid'],
      'banner_media_id' => [$sometimes, 'uuid'],
      'trailer_media_id' => [$sometimes, 'uuid'],
      'trailer_youtube_url' => [$sometimes, 'string', 'max:500'],
      'youtube_playlist_url' => [$sometimes, 'string', 'max:500'],
      'duration_minutes' => [$sometimes, 'integer', 'min:0'],
      'estimated_completion_minutes' => [$sometimes, 'integer', 'min:0'],
      'requirements' => [$sometimes, 'array'],
      'requirements.*' => ['string', 'max:500'],
      'learning_objectives' => [$sometimes, 'array'],
      'learning_objectives.*' => ['string', 'max:500'],
      'seo_title' => [$sometimes, 'string', 'max:255'],
      'seo_description' => [$sometimes, 'string'],
      'seo_keywords' => [$sometimes, 'array'],
      'seo_keywords.*' => ['string', 'max:80'],
      'scheduled_publish_at' => [$sometimes, 'date'],
      'certificate_enabled' => ['sometimes', 'boolean'],
      'certificate_template_id' => [$sometimes, 'uuid'],
      'certificate_requires_assessment_pass' => ['sometimes', 'boolean'],
      'certificate_min_score' => [$sometimes, 'integer', 'min:0', 'max:100'],
      'certificate_min_completion_percent' => [$sometimes, 'integer', 'min:0', 'max:100'],
      'certificate_auto_issue' => ['sometimes', 'boolean'],
      'assessment_required' => ['sometimes', 'boolean'],
      'assignment_required' => ['sometimes', 'boolean'],
      'passing_score' => [$sometimes, 'numeric', 'min:0', 'max:100'],
      'max_attempts' => [$sometimes, 'integer', 'min:1'],
      'completion_rule' => [$sometimes, 'string', Rule::in([
        'all_mandatory_lessons', 'all_lessons', 'percent_threshold',
        'assessment_pass', 'assignment_pass', 'assessment_and_assignment',
      ])],
      'tag_ids' => ['sometimes', 'array'],
      'tag_ids.*' => ['uuid'],
      'instructors' => ['sometimes', 'array'],
      'instructors.*.id' => ['required_with:instructors', 'uuid'],
      'instructors.*.is_primary' => ['sometimes', 'boolean'],
      'instructors.*.sort_order' => ['sometimes', 'integer'],
      'instructors.*.role_label' => ['nullable', 'string', 'max:120'],
      'metadata' => ['sometimes', 'array'],
    ]);
  }
}
