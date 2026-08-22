<?php

declare(strict_types=1);

namespace App\Modules\Lms\Services;

use App\Contracts\ServiceContract;
use App\Models\User;
use App\Modules\Lms\Models\Announcement;
use App\Modules\Lms\Models\Bookmark;
use App\Modules\Lms\Models\Course;
use App\Modules\Lms\Models\CourseCategory;
use App\Modules\Lms\Models\CourseCertificate;
use App\Modules\Lms\Models\CourseDownload;
use App\Modules\Lms\Models\Enrollment;
use App\Modules\Lms\Models\LearningActivity;
use App\Modules\Lms\Models\Lesson;
use App\Modules\Lms\Models\LessonNote;
use App\Modules\Lms\Models\LessonProgress;
use App\Modules\Lms\Models\LessonResource;
use App\Modules\Lms\Models\SchoolEnrollment;
use App\Modules\Lms\Models\Wishlist;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class LearningExperienceService implements ServiceContract
{
  public function __construct(
    private readonly ProgressService $progress,
    private readonly CurriculumProgressionService $progression,
    private readonly LearnerCurriculumService $curriculumTree,
  ) {}

  private function enumValue(mixed $value): string
  {
    if ($value instanceof \BackedEnum) {
      return (string) $value->value;
    }

    return (string) $value;
  }

  public function recordActivity(
    User $user,
    string $eventType,
    string $title,
    ?string $description = null,
    ?Course $course = null,
    ?Enrollment $enrollment = null,
    ?Lesson $lesson = null,
    ?array $metadata = null,
  ): LearningActivity {
    return LearningActivity::query()->create([
      'user_id' => $user->id,
      'course_id' => $course?->id ?? $enrollment?->course_id,
      'enrollment_id' => $enrollment?->id,
      'lesson_id' => $lesson?->id,
      'event_type' => $eventType,
      'title' => $title,
      'description' => $description,
      'metadata' => $metadata,
      'occurred_at' => now(),
    ]);
  }

  /** @return array<string, mixed> */
  public function experienceDashboard(User $user): array
  {
    $enrollments = Enrollment::query()
      ->where('user_id', $user->id)
      ->whereIn('status', ['active', 'completed'])
      ->with([
        'course.coverMedia',
        'course.category.coverMedia',
        'course.school',
        'course.programModule',
        'course.modules' => fn ($q) => $q->where('status', 'published')->orderBy('sort_order'),
        'course.modules.lessons' => fn ($q) => $q->where('status', 'published')->orderBy('sort_order'),
        'course.modules.assessments' => fn ($q) => $q->where('status', 'published')->whereNull('lesson_id'),
        'certificate',
        'lessonProgress.lesson',
      ])
      ->latest('enrolled_at')
      ->get();

    $continue = $enrollments
      ->filter(fn (Enrollment $e) => $this->enumValue($e->status) === 'active')
      ->map(fn (Enrollment $e) => $this->continueLearningPayload($e))
      ->filter()
      ->values()
      ->take(6);

    $courseIds = $enrollments->pluck('course_id')->filter()->unique()->all();

    $schoolEnrollmentModels = SchoolEnrollment::query()
      ->where('user_id', $user->id)
      ->whereIn('status', ['active', 'completed'])
      ->with(['school.coverMedia', 'school.thumbnailMedia'])
      ->latest('enrolled_at')
      ->get();

    $schoolEnrollments = $schoolEnrollmentModels->map(fn (SchoolEnrollment $e) => [
      'id' => $e->uuid,
      'status' => $this->enumValue($e->status),
      'progress_percent' => $e->progress_percent !== null ? (float) $e->progress_percent : 0,
      'enrolled_at' => $e->enrolled_at?->toIso8601String(),
      'school' => $e->school ? [
        'id' => $e->school->uuid,
        'slug' => $e->school->slug,
        'title' => $e->school->title,
        'thumbnail_url' => $e->school->relationLoaded('thumbnailMedia')
          ? $e->school->thumbnailMedia?->url()
          : null,
      ] : null,
    ]);

    $learning = $this->curriculumTree->learningTree($enrollments, $schoolEnrollmentModels);

    $freeLearning = $this->freeLearningSummary($enrollments);

    return [
      'schools' => $schoolEnrollments->values(),
      'learning' => $learning,
      'free_learning' => $freeLearning,
      'continue_learning' => $continue,
      'progress' => [
        'courses_active' => $enrollments->filter(fn ($e) => $this->enumValue($e->status) === 'active')->count(),
        'courses_completed' => $enrollments->filter(fn ($e) => $this->enumValue($e->status) === 'completed')->count(),
        'avg_completion' => round((float) ($enrollments->avg('progress_percent') ?? 0), 1),
        'time_spent_seconds' => (int) $enrollments->sum(
          fn (Enrollment $e) => (int) $e->lessonProgress->sum('time_spent_seconds'),
        ),
        'lessons_completed' => $enrollments->sum(
          fn (Enrollment $e) => $e->lessonProgress
            ->filter(fn (LessonProgress $p) => $this->enumValue($p->status) === 'completed')
            ->count(),
        ),
      ],
      'bookmarks' => $this->bookmarksForUser($user)->take(10)->values(),
      'downloads' => $this->downloadsForCourses($courseIds)->take(12)->values(),
      'certificates' => $this->certificatesForUser($user)->take(12)->values(),
      'assignments' => $this->typedLessonsForUser($user, $enrollments, 'assignment')->take(12)->values(),
      'assessments' => $this->typedLessonsForUser($user, $enrollments, 'quiz')->take(12)->values(),
      'announcements' => $this->announcementsForCourses($courseIds)->take(10)->values(),
      'notifications' => $this->notificationsForUser($user, $courseIds)->take(10)->values(),
      'calendar' => $this->calendarForUser($user, $enrollments)->take(20)->values(),
      'recent_activity' => $this->recentActivity($user)->take(20)->values(),
      'stats' => [
        'active' => $enrollments->filter(fn ($e) => $this->enumValue($e->status) === 'active')->count(),
        'completed' => $enrollments->filter(fn ($e) => $this->enumValue($e->status) === 'completed')->count(),
        'schools_active' => $schoolEnrollments->filter(fn ($e) => $e['status'] === 'active')->count(),
        'wishlist' => Wishlist::query()->where('user_id', $user->id)->count(),
        'bookmarks' => Bookmark::query()->where('user_id', $user->id)->count(),
        'notes' => LessonNote::query()->where('user_id', $user->id)->count(),
        'certificates' => CourseCertificate::query()->where('user_id', $user->id)->where('status', 'issued')->count(),
      ],
    ];
  }

  /** @return array<string, mixed>|null */
  private function continueLearningPayload(Enrollment $enrollment): ?array
  {
    $course = $enrollment->course;
    if (! $course) {
      return null;
    }
    $course->loadMissing(['school', 'programModule']);

    $progressRows = $enrollment->relationLoaded('lessonProgress')
      ? $enrollment->lessonProgress
      : $enrollment->lessonProgress()->with('lesson')->get();

    $inProgress = $progressRows
      ->filter(fn (LessonProgress $p) => $this->enumValue($p->status) === 'in_progress')
      ->sortByDesc('updated_at')
      ->first();

    $nextLesson = null;
    if ($inProgress?->lesson) {
      $nextLesson = $inProgress->lesson;
      $position = (int) $inProgress->last_position_seconds;
    } else {
      $completedIds = $progressRows
        ->filter(fn (LessonProgress $p) => $this->enumValue($p->status) === 'completed')
        ->pluck('lesson_id')
        ->all();
      $nextLesson = Lesson::query()
        ->where('course_id', $course->id)
        ->where('status', 'published')
        ->whereNotIn('id', $completedIds)
        ->orderBy('sort_order')
        ->first();
      $position = 0;
    }

    return [
      'enrollment_id' => $enrollment->uuid,
      'progress_percent' => (float) $enrollment->progress_percent,
      'status' => $enrollment->status instanceof \BackedEnum ? $enrollment->status->value : $enrollment->status,
      'course' => [
        'id' => $course->uuid,
        'title' => $course->title,
        'slug' => $course->slug,
        'cover_url' => $course->relationLoaded('coverMedia') && $course->coverMedia
          ? $course->coverMedia->url()
          : null,
      ],
      'school' => $course->school ? [
        'id' => $course->school->uuid,
        'title' => $course->school->title,
        'slug' => $course->school->slug,
      ] : null,
      'program_module' => $this->curriculumTree->programModuleRef($course->programModule),
      'resume_lesson' => $nextLesson ? [
        'id' => $nextLesson->uuid,
        'title' => $nextLesson->title,
        'lesson_type' => $nextLesson->lesson_type instanceof \BackedEnum
          ? $nextLesson->lesson_type->value
          : $nextLesson->lesson_type,
        'position_seconds' => $position ?? 0,
      ] : null,
      'curriculum_summary' => $this->curriculumSummaryForEnrollment($enrollment, $course),
    ];
  }

  /** @return array<string, mixed> */
  private function curriculumSummaryForEnrollment(Enrollment $enrollment, Course $course): array
  {
    $locks = $this->progression->curriculumLockMap($enrollment, $course);
    $modules = $locks['modules'];
    $completedModules = collect($modules)->where('completed', true)->count();
    $current = collect($modules)->firstWhere('id', $locks['current_module_id']);

    return [
      'sequential' => $locks['sequential'],
      'modules_total' => count($modules),
      'modules_completed' => $completedModules,
      'current_module_id' => $locks['current_module_id'],
      'current_module_access_state' => $current['access_state'] ?? null,
    ];
  }

  public function bookmarksForUser(User $user): Collection
  {
    return Bookmark::query()
      ->where('user_id', $user->id)
      ->with(['lesson.course:id,uuid,title,slug'])
      ->latest()
      ->get()
      ->map(fn (Bookmark $b) => [
        'id' => $b->uuid,
        'note' => $b->note,
        'label' => $b->label,
        'position_seconds' => $b->position_seconds,
        'lesson' => $b->lesson ? [
          'id' => $b->lesson->uuid,
          'title' => $b->lesson->title,
          'course' => $b->lesson->course ? [
            'id' => $b->lesson->course->uuid,
            'title' => $b->lesson->course->title,
            'slug' => $b->lesson->course->slug,
          ] : null,
        ] : null,
      ]);
  }

  /** @param  list<int>  $courseIds */
  public function downloadsForCourses(array $courseIds): Collection
  {
    if ($courseIds === []) {
      return collect();
    }

    $courseDownloads = CourseDownload::query()
      ->whereIn('course_id', $courseIds)
      ->with(['course:id,uuid,title,slug', 'fileMedia'])
      ->orderBy('sort_order')
      ->get()
      ->map(fn (CourseDownload $d) => [
        'id' => $d->uuid,
        'scope' => 'course',
        'title' => $d->title,
        'external_url' => $d->external_url,
        'file_url' => $d->fileMedia ? $d->fileMedia->url() : null,
        'course' => $d->course ? [
          'id' => $d->course->uuid,
          'title' => $d->course->title,
        ] : null,
      ]);

    $lessonIds = Lesson::query()->whereIn('course_id', $courseIds)->pluck('id');
    $lessonResources = LessonResource::query()
      ->whereIn('lesson_id', $lessonIds)
      ->where('is_downloadable', true)
      ->with(['lesson.course:id,uuid,title,slug', 'fileMedia'])
      ->orderBy('sort_order')
      ->get()
      ->map(fn (LessonResource $r) => [
        'id' => $r->uuid,
        'scope' => 'lesson',
        'title' => $r->title,
        'external_url' => $r->external_url,
        'file_url' => $r->fileMedia ? $r->fileMedia->url() : null,
        'course' => $r->lesson?->course ? [
          'id' => $r->lesson->course->uuid,
          'title' => $r->lesson->course->title,
        ] : null,
      ]);

    return $courseDownloads->concat($lessonResources);
  }

  public function certificatesForUser(User $user): Collection
  {
    return CourseCertificate::query()
      ->where('user_id', $user->id)
      ->where('status', 'issued')
      ->with(['course:id,uuid,title,slug', 'certificateMedia'])
      ->latest('issued_at')
      ->get()
      ->map(fn (CourseCertificate $c) => [
        'id' => $c->uuid,
        'certificate_number' => $c->certificate_number,
        'verification_code' => $c->verification_code,
        'issued_at' => $c->issued_at?->toIso8601String(),
        'certificate_url' => $c->certificateMedia?->url(),
        'verification_url' => url('/certificate/'.$c->verification_code),
        'course' => $c->course ? [
          'id' => $c->course->uuid,
          'title' => $c->course->title,
          'slug' => $c->course->slug,
        ] : null,
      ]);
  }

  /** @param  Collection<int, Enrollment>  $enrollments */
  public function typedLessonsForUser(User $user, Collection $enrollments, string $type): Collection
  {
    $courseIds = $enrollments->pluck('course_id')->all();
    if ($courseIds === []) {
      return collect();
    }

    $progressByLesson = LessonProgress::query()
      ->whereIn('enrollment_id', $enrollments->pluck('id'))
      ->get()
      ->keyBy('lesson_id');

    return Lesson::query()
      ->whereIn('course_id', $courseIds)
      ->where('lesson_type', $type)
      ->where('status', 'published')
      ->with(['course:id,uuid,title,slug'])
      ->orderBy('sort_order')
      ->get()
      ->map(function (Lesson $lesson) use ($enrollments, $progressByLesson, $type) {
        $enrollment = $enrollments->firstWhere('course_id', $lesson->course_id);
        $progress = $progressByLesson->get($lesson->id);

        return [
          'id' => $lesson->uuid,
          'title' => $lesson->title,
          'lesson_type' => $type,
          'status' => $progress
            ? ($progress->status instanceof \BackedEnum ? $progress->status->value : $progress->status)
            : 'not_started',
          'progress_percent' => $progress ? (float) $progress->progress_percent : 0,
          'enrollment_id' => $enrollment?->uuid,
          'course' => $lesson->course ? [
            'id' => $lesson->course->uuid,
            'title' => $lesson->course->title,
            'slug' => $lesson->course->slug,
          ] : null,
        ];
      });
  }

  /** @param  list<int>  $courseIds */
  public function announcementsForCourses(array $courseIds): Collection
  {
    return Announcement::query()
      ->where('status', 'published')
      ->where(function ($q) use ($courseIds): void {
        $q->whereNull('course_id');
        if ($courseIds !== []) {
          $q->orWhereIn('course_id', $courseIds);
        }
      })
      ->with(['course:id,uuid,title,slug'])
      ->latest('published_at')
      ->get()
      ->map(fn (Announcement $a) => [
        'id' => $a->uuid,
        'title' => $a->title,
        'body' => $a->body,
        'published_at' => $a->published_at?->toIso8601String(),
        'course' => $a->course ? [
          'id' => $a->course->uuid,
          'title' => $a->course->title,
        ] : null,
      ]);
  }

  /** @param  list<int>  $courseIds */
  public function notificationsForUser(User $user, array $courseIds): Collection
  {
    // LMS notifications surface from published announcements + recent learning activity.
    $fromAnnouncements = $this->announcementsForCourses($courseIds)->map(fn (array $a) => [
      'id' => 'ann-'.$a['id'],
      'type' => 'announcement',
      'title' => $a['title'],
      'body' => $a['body'],
      'occurred_at' => $a['published_at'],
    ]);

    $fromActivity = $this->recentActivity($user)->map(fn (array $a) => [
      'id' => 'act-'.$a['id'],
      'type' => $a['event_type'],
      'title' => $a['title'],
      'body' => $a['description'],
      'occurred_at' => $a['occurred_at'],
    ]);

    return $fromAnnouncements->concat($fromActivity)
      ->sortByDesc('occurred_at')
      ->values();
  }

  /** @param  Collection<int, Enrollment>  $enrollments */
  public function calendarForUser(User $user, Collection $enrollments): Collection
  {
    $events = collect();

    foreach ($enrollments as $enrollment) {
      if ($enrollment->enrolled_at) {
        $events->push([
          'id' => 'enroll-'.$enrollment->uuid,
          'title' => 'Enrolled: '.($enrollment->course?->title ?? 'Course'),
          'type' => 'enrollment',
          'starts_at' => $enrollment->enrolled_at->toIso8601String(),
          'course_id' => $enrollment->course?->uuid,
        ]);
      }
      if ($enrollment->completed_at) {
        $events->push([
          'id' => 'complete-'.$enrollment->uuid,
          'title' => 'Completed: '.($enrollment->course?->title ?? 'Course'),
          'type' => 'completion',
          'starts_at' => $enrollment->completed_at->toIso8601String(),
          'course_id' => $enrollment->course?->uuid,
        ]);
      }
    }

    $courseIds = $enrollments->pluck('course_id')->all();
    foreach ($this->announcementsForCourses($courseIds) as $announcement) {
      if (! empty($announcement['published_at'])) {
        $events->push([
          'id' => 'ann-cal-'.$announcement['id'],
          'title' => $announcement['title'],
          'type' => 'announcement',
          'starts_at' => $announcement['published_at'],
          'course_id' => $announcement['course']['id'] ?? null,
        ]);
      }
    }

    return $events->sortBy('starts_at')->values();
  }

  public function recentActivity(User $user): Collection
  {
    return LearningActivity::query()
      ->where('user_id', $user->id)
      ->with(['course:id,uuid,title,slug', 'lesson:id,uuid,title'])
      ->latest('occurred_at')
      ->limit(50)
      ->get()
      ->map(fn (LearningActivity $a) => [
        'id' => $a->uuid,
        'event_type' => $a->event_type,
        'title' => $a->title,
        'description' => $a->description,
        'occurred_at' => $a->occurred_at?->toIso8601String(),
        'course' => $a->course ? [
          'id' => $a->course->uuid,
          'title' => $a->course->title,
        ] : null,
        'lesson' => $a->lesson ? [
          'id' => $a->lesson->uuid,
          'title' => $a->lesson->title,
        ] : null,
      ]);
  }

  /** @return array<string, mixed> */
  public function playerPayload(User $user, Enrollment $enrollment, Lesson $lesson): array
  {
    abort_unless($enrollment->user_id === $user->id, 403);
    abort_unless($lesson->course_id === $enrollment->course_id, 404);

    $enrollment->load([
      'course.coverMedia',
      'course.school',
      'course.programModule',
      'course.category',
      'course.modules' => fn ($q) => $q->where('status', 'published')->orderBy('sort_order'),
      'course.modules.lessons' => fn ($q) => $q->where('status', 'published')->orderBy('sort_order'),
      'course.modules.lessons.resources.fileMedia',
      'course.modules.lessons.videoMedia',
      'course.downloads.fileMedia',
      'lessonProgress',
      'certificate',
    ]);

    $progress = LessonProgress::query()
      ->where('enrollment_id', $enrollment->id)
      ->where('lesson_id', $lesson->id)
      ->first();

    $lesson->load(['resources.fileMedia', 'videoMedia', 'module']);

    $flatLessons = $enrollment->course->modules
      ->flatMap(fn ($m) => $m->lessons)
      ->values();
    $index = $flatLessons->search(fn (Lesson $l) => $l->id === $lesson->id);
    $next = $index !== false ? $flatLessons->get($index + 1) : null;
    $prev = $index !== false && $index > 0 ? $flatLessons->get($index - 1) : null;

    $progressByLesson = $enrollment->lessonProgress->keyBy('lesson_id');

    $moduleProgress = $enrollment->course->modules->map(function ($module) use ($enrollment, $progressByLesson) {
      $lessonIds = $module->lessons->pluck('id');
      $total = $lessonIds->count();
      $completed = $lessonIds->filter(function ($id) use ($progressByLesson) {
        $row = $progressByLesson->get($id);

        return $row && $this->enumValue($row->status) === 'completed';
      })->count();

      return [
        'id' => $module->uuid,
        'title' => $module->title,
        'lessons_total' => $total,
        'lessons_completed' => $completed,
        'completion_percent' => $total > 0 ? round(($completed / $total) * 100, 1) : 0,
        'locked' => ! $this->progression->isModuleAccessible($enrollment, $module),
        'access_state' => $this->progression->moduleAccessState($enrollment, $module),
      ];
    });

    $locks = $this->progression->curriculumLockMap($enrollment, $enrollment->course);
    $currentModule = $lesson->module;
    $school = $enrollment->course->school;
    $programModule = $enrollment->course->programModule;
    $schoolCurriculum = $school
      ? $this->markCurrentCourse($this->curriculumTree->schoolCurriculumForLearner($user, $school), $enrollment->course->uuid)
      : null;

    return [
      'enrollment' => [
        'id' => $enrollment->uuid,
        'progress_percent' => (float) $enrollment->progress_percent,
        'status' => $enrollment->status instanceof \BackedEnum ? $enrollment->status->value : $enrollment->status,
        'time_spent_seconds' => (int) $enrollment->lessonProgress->sum('time_spent_seconds'),
      ],
      'school' => $school ? [
        'id' => $school->uuid,
        'title' => $school->title,
        'slug' => $school->slug,
      ] : null,
      'program_module' => $this->curriculumTree->programModuleRef($programModule),
      'course' => [
        'id' => $enrollment->course->uuid,
        'title' => $enrollment->course->title,
        'slug' => $enrollment->course->slug,
      ],
      'current_module' => $currentModule ? [
        'id' => $currentModule->uuid,
        'title' => $currentModule->title,
        'sort_order' => (int) $currentModule->sort_order,
      ] : null,
      'hierarchy' => [
        'school_title' => $school?->title,
        'program_module_number' => $programModule ? (int) $programModule->sort_order : null,
        'program_module_title' => $programModule?->title,
        'course_title' => $enrollment->course->title,
        'course_module_title' => $currentModule?->title,
        'module_title' => $programModule?->title ?? $currentModule?->title,
        'lesson_title' => $lesson->title,
      ],
      'school_curriculum' => $schoolCurriculum,
      'modules' => $moduleProgress,
      'lesson' => [
        'id' => $lesson->uuid,
        'title' => $lesson->title,
        'summary' => $lesson->summary,
        'content' => $lesson->content,
        'lesson_type' => $lesson->lesson_type instanceof \BackedEnum ? $lesson->lesson_type->value : $lesson->lesson_type,
        'video_source' => $lesson->video_source instanceof \BackedEnum ? $lesson->video_source->value : $lesson->video_source,
        'youtube_video_id' => $lesson->youtube_video_id,
        'youtube_url' => $lesson->youtube_url,
        'video_url' => $lesson->videoMedia?->url(),
        'embed_html' => $lesson->embed_html,
        'duration_minutes' => $lesson->duration_minutes,
        'completion_threshold_percent' => (int) $lesson->completion_threshold_percent,
        'resources' => $lesson->resources->map(fn ($r) => [
          'id' => $r->uuid,
          'title' => $r->title,
          'resource_type' => $r->resource_type instanceof \BackedEnum ? $r->resource_type->value : $r->resource_type,
          'external_url' => $r->external_url,
          'file_url' => $r->fileMedia ? $r->fileMedia->url() : null,
          'is_downloadable' => (bool) $r->is_downloadable,
        ]),
      ],
      'progress' => [
        'status' => $progress
          ? ($progress->status instanceof \BackedEnum ? $progress->status->value : $progress->status)
          : 'not_started',
        'progress_percent' => $progress ? (float) $progress->progress_percent : 0,
        'last_position_seconds' => $progress ? (int) $progress->last_position_seconds : 0,
        'time_spent_seconds' => $progress ? (int) $progress->time_spent_seconds : 0,
      ],
      'navigation' => [
        'previous_lesson_id' => $prev?->uuid,
        'next_lesson_id' => $next?->uuid,
        'auto_next' => true,
      ],
      'progression' => $locks,
      'curriculum' => $enrollment->course->modules->map(function ($m) use ($locks, $enrollment) {
        $moduleLock = collect($locks['modules'])->firstWhere('id', $m->uuid);
        $lessonLockMap = collect($moduleLock['lessons'] ?? [])->keyBy('id');
        $assessmentLockMap = collect($moduleLock['assessments'] ?? [])->keyBy('id');

        $m->loadMissing(['assessments' => fn ($q) => $q->where('status', 'published')->whereNull('lesson_id')]);

        return [
          'id' => $m->uuid,
          'title' => $m->title,
          'description' => $m->description,
          'sort_order' => (int) $m->sort_order,
          'locked' => (bool) ($moduleLock['locked'] ?? false),
          'access_state' => $moduleLock['access_state'] ?? ($this->progression->moduleAccessState($enrollment, $m)),
          'completed' => (bool) ($moduleLock['completed'] ?? false),
          'lessons' => $m->lessons->map(fn (Lesson $l) => [
            'id' => $l->uuid,
            'title' => $l->title,
            'lesson_type' => $l->lesson_type instanceof \BackedEnum ? $l->lesson_type->value : $l->lesson_type,
            'duration_minutes' => $l->duration_minutes,
            'locked' => (bool) ($lessonLockMap->get($l->uuid)['locked'] ?? false),
            'completed' => (bool) ($lessonLockMap->get($l->uuid)['completed'] ?? false),
            'access_state' => $lessonLockMap->get($l->uuid)['access_state']
              ?? $this->progression->lessonAccessState($enrollment, $l),
          ]),
          'assessments' => $m->assessments->map(fn ($a) => [
            'id' => $a->uuid,
            'title' => $a->title,
            'assessment_type' => $a->assessment_type instanceof \BackedEnum
              ? $a->assessment_type->value
              : $a->assessment_type,
            'pass_mark' => (float) $a->pass_mark,
            'locked' => (bool) ($assessmentLockMap->get($a->uuid)['locked'] ?? false),
            'completed' => (bool) ($assessmentLockMap->get($a->uuid)['completed'] ?? false),
            'access_state' => $assessmentLockMap->get($a->uuid)['access_state'] ?? 'available',
          ]),
        ];
      }),
    ];
  }

  /**
   * Full hierarchical curriculum for an enrollment (learner outline dashboard).
   *
   * @return array<string, mixed>
   */
  public function enrollmentCurriculum(User $user, Enrollment $enrollment): array
  {
    abort_unless($enrollment->user_id === $user->id, 403);

    return $this->curriculumSnapshot($enrollment);
  }

  /**
   * Curriculum snapshot for an enrollment (learner or admin).
   *
   * @return array<string, mixed>
   */
  public function curriculumSnapshot(Enrollment $enrollment): array
  {
    $enrollment->load([
      'course.coverMedia',
      'course.school',
      'course.programModule',
      'course.modules' => fn ($q) => $q->where('status', 'published')->orderBy('sort_order'),
      'course.modules.lessons' => fn ($q) => $q->where('status', 'published')->orderBy('sort_order'),
      'course.modules.assessments' => fn ($q) => $q->where('status', 'published')->whereNull('lesson_id'),
      'lessonProgress',
      'certificate',
      'user:id,uuid,name,email',
    ]);

    $course = $enrollment->course;
    abort_unless($course !== null, 404);

    $locks = $this->progression->curriculumLockMap($enrollment, $course);
    $moduleLockById = collect($locks['modules'])->keyBy('id');

    $modules = $course->modules->map(function ($module) use ($enrollment, $moduleLockById) {
      $lock = $moduleLockById->get($module->uuid, []);
      $lessonLocks = collect($lock['lessons'] ?? [])->keyBy('id');
      $assessmentLocks = collect($lock['assessments'] ?? [])->keyBy('id');

      $lessonIds = $module->lessons->pluck('id');
      $total = $lessonIds->count();
      $completedCount = LessonProgress::query()
        ->where('enrollment_id', $enrollment->id)
        ->whereIn('lesson_id', $lessonIds)
        ->where('status', 'completed')
        ->count();

      return [
        'id' => $module->uuid,
        'title' => $module->title,
        'description' => $module->description,
        'sort_order' => (int) $module->sort_order,
        'access_state' => $lock['access_state'] ?? $this->progression->moduleAccessState($enrollment, $module),
        'locked' => (bool) ($lock['locked'] ?? false),
        'completed' => (bool) ($lock['completed'] ?? false),
        'lessons_total' => $total,
        'lessons_completed' => $completedCount,
        'completion_percent' => $total > 0 ? round(($completedCount / $total) * 100, 1) : 0.0,
        'lessons' => $module->lessons->map(fn (Lesson $lesson) => [
          'id' => $lesson->uuid,
          'title' => $lesson->title,
          'summary' => $lesson->summary,
          'lesson_type' => $lesson->lesson_type instanceof \BackedEnum
            ? $lesson->lesson_type->value
            : $lesson->lesson_type,
          'duration_minutes' => $lesson->duration_minutes,
          'is_mandatory' => (bool) $lesson->is_mandatory,
          'sort_order' => (int) $lesson->sort_order,
          'access_state' => $lessonLocks->get($lesson->uuid)['access_state']
            ?? $this->progression->lessonAccessState($enrollment, $lesson),
          'locked' => (bool) ($lessonLocks->get($lesson->uuid)['locked'] ?? false),
          'completed' => (bool) ($lessonLocks->get($lesson->uuid)['completed'] ?? false),
        ])->values(),
        'assessments' => $module->assessments->map(fn ($assessment) => [
          'id' => $assessment->uuid,
          'title' => $assessment->title,
          'assessment_type' => $assessment->assessment_type instanceof \BackedEnum
            ? $assessment->assessment_type->value
            : $assessment->assessment_type,
          'pass_mark' => (float) $assessment->pass_mark,
          'access_state' => $assessmentLocks->get($assessment->uuid)['access_state'] ?? 'available',
          'locked' => (bool) ($assessmentLocks->get($assessment->uuid)['locked'] ?? false),
          'completed' => (bool) ($assessmentLocks->get($assessment->uuid)['completed'] ?? false),
        ])->values(),
      ];
    })->values();

    $currentModule = $modules->first(
      fn (array $m) => in_array($m['access_state'], ['available', 'in_progress'], true),
    );

    $learner = $enrollment->user;
    $schoolCurriculum = $course->school && $learner instanceof User
      ? $this->markCurrentCourse(
        $this->curriculumTree->schoolCurriculumForLearner($learner, $course->school),
        $course->uuid,
      )
      : null;

    return [
      'enrollment' => [
        'id' => $enrollment->uuid,
        'status' => $this->enumValue($enrollment->status),
        'progress_percent' => (float) $enrollment->progress_percent,
        'learner_type' => $enrollment->learner_type instanceof \BackedEnum
          ? $enrollment->learner_type->value
          : $enrollment->learner_type,
        'enrolled_at' => $enrollment->enrolled_at?->toIso8601String(),
        'completed_at' => $enrollment->completed_at?->toIso8601String(),
        'last_accessed_at' => $enrollment->last_accessed_at?->toIso8601String(),
      ],
      'learner' => $enrollment->user ? [
        'id' => $enrollment->user->uuid,
        'name' => $enrollment->user->name,
        'email' => $enrollment->user->email,
      ] : null,
      'course' => [
        'id' => $course->uuid,
        'title' => $course->title,
        'slug' => $course->slug,
        'cover_url' => $course->relationLoaded('coverMedia') ? $course->coverMedia?->url() : null,
      ],
      'school' => $course->school ? [
        'id' => $course->school->uuid,
        'title' => $course->school->title,
        'slug' => $course->school->slug,
      ] : null,
      'program_module' => $this->curriculumTree->programModuleRef($course->programModule),
      'school_curriculum' => $schoolCurriculum,
      'progression' => [
        'sequential' => $locks['sequential'],
        'current_module_id' => $locks['current_module_id'],
      ],
      'current_module' => $currentModule ? [
        'id' => $currentModule['id'],
        'title' => $currentModule['title'],
        'access_state' => $currentModule['access_state'],
      ] : null,
      'modules' => $modules,
      'certificate' => $enrollment->certificate ? [
        'id' => $enrollment->certificate->uuid,
        'certificate_number' => $enrollment->certificate->certificate_number,
        'issued_at' => $enrollment->certificate->issued_at?->toIso8601String(),
      ] : null,
    ];
  }

  public function schoolCurriculum(User $user, \App\Modules\Lms\Models\LmsSchool $school): array
  {
    return $this->curriculumTree->schoolCurriculumForLearner($user, $school);
  }

  /**
   * @param  array<string, mixed>  $curriculum
   * @return array<string, mixed>
   */
  private function markCurrentCourse(array $curriculum, string $courseUuid): array
  {
    $curriculum['modules'] = collect($curriculum['modules'] ?? [])
      ->map(function (array $module) use ($courseUuid) {
        $module['courses'] = collect($module['courses'] ?? [])
          ->map(function (array $course) use ($courseUuid) {
            $course['is_current'] = ($course['id'] ?? null) === $courseUuid;

            return $course;
          })
          ->all();

        return $module;
      })
      ->all();

    $curriculum['unassigned_courses'] = collect($curriculum['unassigned_courses'] ?? [])
      ->map(function (array $course) use ($courseUuid) {
        $course['is_current'] = ($course['id'] ?? null) === $courseUuid;

        return $course;
      })
      ->all();

    return $curriculum;
  }

  /** @return array<string, mixed> */
  public function adminProgressDashboard(): array
  {
    $enrollments = Enrollment::query()->count();
    $completed = Enrollment::query()->where('status', 'completed')->count();
    $active = Enrollment::query()->where('status', 'active')->count();
    $avgProgress = (float) Enrollment::query()->avg('progress_percent');
    $timeSpent = (int) LessonProgress::query()->sum('time_spent_seconds');
    $lessonsCompleted = LessonProgress::query()->where('status', 'completed')->count();

    $courseProgress = Course::query()
      ->withCount(['enrollments', 'lessons'])
      ->orderByDesc('enrollment_count')
      ->limit(20)
      ->get()
      ->map(function (Course $course) {
        $avg = (float) Enrollment::query()->where('course_id', $course->id)->avg('progress_percent');
        $completed = Enrollment::query()->where('course_id', $course->id)->where('status', 'completed')->count();
        $time = (int) LessonProgress::query()
          ->whereIn('enrollment_id', Enrollment::query()->where('course_id', $course->id)->select('id'))
          ->sum('time_spent_seconds');

        return [
          'id' => $course->uuid,
          'title' => $course->title,
          'enrollments' => (int) $course->enrollments_count,
          'lessons' => (int) $course->lessons_count,
          'avg_completion' => round($avg, 1),
          'completed' => $completed,
          'time_spent_seconds' => $time,
        ];
      });

    $studentProgress = DB::table('lms_enrollments')
      ->join('users', 'users.id', '=', 'lms_enrollments.user_id')
      ->whereNull('lms_enrollments.deleted_at')
      ->select([
        'users.uuid as id',
        'users.name',
        'users.email',
        DB::raw('COUNT(lms_enrollments.id) as enrollments_count'),
        DB::raw('AVG(lms_enrollments.progress_percent) as avg_progress'),
        DB::raw('SUM(CASE WHEN lms_enrollments.status = \'completed\' THEN 1 ELSE 0 END) as completed_count'),
      ])
      ->groupBy('users.id', 'users.uuid', 'users.name', 'users.email')
      ->orderByDesc(DB::raw('AVG(lms_enrollments.progress_percent)'))
      ->limit(25)
      ->get()
      ->map(fn ($row) => [
        'id' => $row->id,
        'name' => $row->name,
        'email' => $row->email,
        'enrollments_count' => (int) $row->enrollments_count,
        'avg_progress' => round((float) $row->avg_progress, 1),
        'completed_count' => (int) $row->completed_count,
      ]);

    return [
      'summary' => [
        'enrollments_total' => $enrollments,
        'enrollments_active' => $active,
        'enrollments_completed' => $completed,
        'completion_rate' => $enrollments > 0 ? round(($completed / $enrollments) * 100, 1) : 0,
        'avg_progress' => round($avgProgress, 1),
        'lessons_completed' => $lessonsCompleted,
        'time_spent_seconds' => $timeSpent,
        'engagement_score' => $enrollments > 0
          ? round(min(100, (($lessonsCompleted / max(1, $enrollments)) * 10) + ($avgProgress / 2)), 1)
          : 0,
      ],
      'course_progress' => $courseProgress,
      'student_progress' => $studentProgress,
    ];
  }

  /** @return list<array<string, mixed>> */
  private function freeLearningSummary(Collection $enrollments): array
  {
    $freeEnrollments = $enrollments->filter(function (Enrollment $enrollment): bool {
      $course = $enrollment->course;
      if (! $course || $course->school_id !== null) {
        return false;
      }

      $category = $course->relationLoaded('category')
        ? $course->category
        : $course->category()->first();

      return $category instanceof CourseCategory && (bool) $category->is_free_learning_hub;
    });

    if ($freeEnrollments->isEmpty()) {
      return [];
    }

    $categoryIds = $freeEnrollments
      ->map(fn (Enrollment $e) => $e->course?->category_id)
      ->filter()
      ->unique()
      ->all();

    $categories = CourseCategory::query()
      ->whereIn('id', $categoryIds)
      ->get()
      ->keyBy('id');

    return $freeEnrollments
      ->groupBy(fn (Enrollment $e) => $e->course?->category_id)
      ->map(function (Collection $group, $categoryId) use ($categories): array {
        $category = $categories->get((int) $categoryId);
        $active = $group->filter(fn (Enrollment $e) => $this->enumValue($e->status) === 'active');
        $completed = $group->filter(fn (Enrollment $e) => $this->enumValue($e->status) === 'completed');

        return [
          'category' => $category ? [
            'id' => $category->uuid,
            'slug' => $category->slug,
            'name' => $category->name,
            'cover_url' => $category->relationLoaded('coverMedia') ? $category->coverMedia?->url() : null,
          ] : null,
          'courses_active' => $active->count(),
          'courses_completed' => $completed->count(),
          'avg_progress' => round((float) ($group->avg('progress_percent') ?? 0), 1),
          'courses' => $group->map(fn (Enrollment $e) => [
            'id' => $e->course?->uuid,
            'title' => $e->course?->title,
            'slug' => $e->course?->slug,
            'progress_percent' => (float) ($e->progress_percent ?? 0),
            'status' => $this->enumValue($e->status),
            'enrollment_id' => $e->uuid,
          ])->values()->all(),
        ];
      })
      ->filter(fn (array $row) => $row['category'] !== null)
      ->values()
      ->all();
  }
}
