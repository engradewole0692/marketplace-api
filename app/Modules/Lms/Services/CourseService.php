<?php

declare(strict_types=1);

namespace App\Modules\Lms\Services;

use App\Contracts\ServiceContract;
use App\Models\User;
use App\Modules\Cms\Models\CmsMedia;
use App\Modules\Lms\Enums\CourseStatus;
use App\Modules\Lms\Models\Course;
use App\Modules\Lms\Models\CourseCategory;
use App\Modules\Lms\Models\CourseLanguage;
use App\Modules\Lms\Models\CourseLevel;
use App\Modules\Lms\Models\Instructor;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class CourseService implements ServiceContract
{
  public function __construct(
    private readonly LmsAuditService $auditService,
  ) {}

  /**
   * @param  array<string, mixed>  $filters
   */
  public function paginate(array $filters = [], int $perPage = 25): LengthAwarePaginator
  {
    $query = Course::query()
      ->with(['category', 'level', 'language', 'coverMedia', 'instructors'])
      ->withCount(['enrollments', 'modules', 'lessons']);

    if (! empty($filters['search'])) {
      $search = (string) $filters['search'];
      $query->where(function ($q) use ($search): void {
        $q->where('title', 'like', "%{$search}%")
          ->orWhere('slug', 'like', "%{$search}%")
          ->orWhere('course_code', 'like', "%{$search}%")
          ->orWhere('summary', 'like', "%{$search}%");
      });
    }

    if (! empty($filters['status'])) {
      $statuses = array_filter(array_map('trim', explode(',', (string) $filters['status'])));
      $query->whereIn('status', $statuses);
    }

    if (! empty($filters['category_id'])) {
      $category = CourseCategory::query()->where('uuid', $filters['category_id'])->first();
      if ($category) {
        $query->where('category_id', $category->id);
      }
    }

    if (isset($filters['featured']) && filter_var($filters['featured'], FILTER_VALIDATE_BOOLEAN)) {
      $query->where('is_featured', true);
    }

    if (isset($filters['popular']) && filter_var($filters['popular'], FILTER_VALIDATE_BOOLEAN)) {
      $query->where('is_popular', true);
    }

    if (isset($filters['recommended']) && filter_var($filters['recommended'], FILTER_VALIDATE_BOOLEAN)) {
      $query->where('is_recommended', true);
    }

    $sort = (string) ($filters['sort'] ?? 'latest');
    match ($sort) {
      'title' => $query->orderBy('title'),
      'popular' => $query->orderByDesc('enrollment_count'),
      'rating' => $query->orderByDesc('average_rating'),
      default => $query->orderByDesc('created_at'),
    };

    return $query->paginate(min(100, max(1, (int) ($filters['per_page'] ?? $perPage))));
  }

  /**
   * @param  array<string, mixed>  $data
   */
  public function create(array $data, User $actor): Course
  {
    return DB::transaction(function () use ($data, $actor): Course {
      $payload = $this->normalizePayload($data);
      $payload['slug'] = $this->uniqueSlug($payload['slug'] ?? Str::slug($payload['title']));
      $payload['course_code'] = $payload['course_code'] ?? $this->nextCourseCode();
      $payload['created_by_user_id'] = $actor->id;
      $payload['updated_by_user_id'] = $actor->id;
      $payload['status'] = $payload['status'] ?? CourseStatus::Draft->value;

      $course = Course::query()->create($payload);
      $this->syncRelations($course, $data);

      $this->auditService->record($course, $actor, 'course.created', 'Course created.', null, $course->toArray());

      return $course->fresh([
        'category', 'subcategory', 'level', 'language', 'coverMedia', 'bannerMedia',
        'instructors', 'tags', 'ministries', 'primaryMinistry',
      ]);
    });
  }

  /**
   * @param  array<string, mixed>  $data
   */
  public function update(Course $course, array $data, User $actor): Course
  {
    return DB::transaction(function () use ($course, $data, $actor): Course {
      $old = $course->toArray();
      $payload = $this->normalizePayload($data);
      if (isset($payload['slug'])) {
        $payload['slug'] = $this->uniqueSlug($payload['slug'], $course->id);
      }
      $payload['updated_by_user_id'] = $actor->id;

      $course->fill($payload)->save();
      $this->syncRelations($course, $data);

      $this->auditService->record($course, $actor, 'course.updated', 'Course updated.', $old, $course->fresh()->toArray());

      return $course->fresh([
        'category', 'subcategory', 'level', 'language', 'coverMedia', 'bannerMedia',
        'instructors', 'tags', 'modules.lessons', 'ministries', 'primaryMinistry',
      ]);
    });
  }

  public function publish(Course $course, User $actor): Course
  {
    $course->forceFill([
      'status' => CourseStatus::Published,
      'published_at' => $course->published_at ?? now(),
      'scheduled_publish_at' => null,
      'updated_by_user_id' => $actor->id,
    ])->save();

    $this->auditService->record($course, $actor, 'course.published', 'Course published.');

    return $course->fresh(['category', 'instructors', 'coverMedia', 'ministries']);
  }

  public function unpublish(Course $course, User $actor): Course
  {
    $course->forceFill([
      'status' => CourseStatus::Draft,
      'updated_by_user_id' => $actor->id,
    ])->save();

    $this->auditService->record($course, $actor, 'course.unpublished', 'Course unpublished.');

    return $course->fresh();
  }

  public function archive(Course $course, User $actor): Course
  {
    $course->forceFill([
      'status' => CourseStatus::Archived,
      'updated_by_user_id' => $actor->id,
    ])->save();

    $this->auditService->record($course, $actor, 'course.archived', 'Course archived.');

    return $course->fresh();
  }

  public function duplicate(Course $course, User $actor): Course
  {
    return DB::transaction(function () use ($course, $actor): Course {
      $course->load(['modules.lessons.resources', 'tags', 'instructors', 'faqs', 'downloads', 'ministries']);

      $copy = $course->replicate(['uuid', 'slug', 'course_code', 'published_at', 'enrollment_count', 'average_rating', 'review_count']);
      $copy->title = $course->title.' (Copy)';
      $copy->slug = $this->uniqueSlug(Str::slug($copy->title));
      $copy->course_code = $this->nextCourseCode();
      $copy->status = CourseStatus::Draft;
      $copy->published_at = null;
      $copy->scheduled_publish_at = null;
      $copy->enrollment_count = 0;
      $copy->created_by_user_id = $actor->id;
      $copy->updated_by_user_id = $actor->id;
      $copy->save();

      $copy->tags()->sync($course->tags->pluck('id'));
      $copy->ministries()->sync($course->ministries->pluck('id'));
      $copy->instructors()->sync(
        $course->instructors->mapWithKeys(fn ($i) => [
          $i->id => [
            'is_primary' => (bool) $i->pivot->is_primary,
            'sort_order' => (int) $i->pivot->sort_order,
            'role_label' => $i->pivot->role_label,
          ],
        ])->all()
      );

      foreach ($course->modules as $module) {
        $moduleCopy = $module->replicate(['uuid']);
        $moduleCopy->course_id = $copy->id;
        $moduleCopy->created_by_user_id = $actor->id;
        $moduleCopy->save();

        foreach ($module->lessons as $lesson) {
          $lessonCopy = $lesson->replicate(['uuid']);
          $lessonCopy->module_id = $moduleCopy->id;
          $lessonCopy->course_id = $copy->id;
          $lessonCopy->created_by_user_id = $actor->id;
          $lessonCopy->save();

          foreach ($lesson->resources as $resource) {
            $resourceCopy = $resource->replicate(['uuid']);
            $resourceCopy->lesson_id = $lessonCopy->id;
            $resourceCopy->save();
          }
        }
      }

      $this->auditService->record($copy, $actor, 'course.duplicated', 'Course duplicated.', ['source' => $course->uuid]);

      return $copy->fresh(['modules.lessons', 'tags', 'instructors', 'ministries']);
    });
  }

  /** Alias for duplicate — clone is the same operational action. */
  public function clone(Course $course, User $actor): Course
  {
    return $this->duplicate($course, $actor);
  }

  public function schedulePublish(Course $course, \DateTimeInterface|string $when, User $actor): Course
  {
    $course->forceFill([
      'scheduled_publish_at' => $when,
      'status' => CourseStatus::ComingSoon,
      'updated_by_user_id' => $actor->id,
    ])->save();

    $this->auditService->record($course, $actor, 'course.schedule_publish', 'Course scheduled for publishing.', null, [
      'scheduled_publish_at' => $course->scheduled_publish_at?->toIso8601String(),
    ]);

    return $course->fresh();
  }

  public function delete(Course $course, User $actor): void
  {
    $course->forceFill(['deleted_by_user_id' => $actor->id])->save();
    $this->auditService->record($course, $actor, 'course.deleted', 'Course deleted.');
    $course->delete();
  }

  /**
   * @param  array<string, mixed>  $filters
   */
  public function paginatePublic(array $filters = [], ?User $viewer = null): LengthAwarePaginator
  {
    $filters['status'] = implode(',', [
      CourseStatus::Published->value,
      CourseStatus::ComingSoon->value,
    ]);

    $paginator = $this->paginate($filters);

    if ($viewer) {
      // Member ministry visibility is applied in query below for list endpoints that pass ministry ids.
    }

    if (! empty($filters['ministry_ids']) && is_array($filters['ministry_ids'])) {
      // Already handled via scope when building — see applyMinistryVisibility.
    }

    return $paginator;
  }

  /**
   * Public listing with optional member ministry visibility.
   *
   * @param  array<string, mixed>  $filters
   * @param  list<int>  $memberMinistryIds
   */
  public function paginateVisible(array $filters = [], array $memberMinistryIds = []): LengthAwarePaginator
  {
    $filters['status'] = implode(',', [
      CourseStatus::Published->value,
      CourseStatus::ComingSoon->value,
    ]);

    $query = Course::query()
      ->with(['category', 'level', 'language', 'coverMedia', 'instructors', 'ministries'])
      ->withCount(['enrollments', 'modules', 'lessons'])
      ->whereIn('status', [
        CourseStatus::Published->value,
        CourseStatus::ComingSoon->value,
      ]);

    if (! empty($filters['search'])) {
      $search = (string) $filters['search'];
      $query->where(function ($q) use ($search): void {
        $q->where('title', 'like', "%{$search}%")
          ->orWhere('slug', 'like', "%{$search}%")
          ->orWhere('summary', 'like', "%{$search}%");
      });
    }

    if (! empty($filters['category_id'])) {
      $category = CourseCategory::query()->where('uuid', $filters['category_id'])->first();
      if ($category) {
        $query->where(function ($q) use ($category): void {
          $q->where('category_id', $category->id)->orWhere('subcategory_id', $category->id);
        });
      }
    }

    if (isset($filters['featured']) && filter_var($filters['featured'], FILTER_VALIDATE_BOOLEAN)) {
      $query->where('is_featured', true);
    }
    if (isset($filters['popular']) && filter_var($filters['popular'], FILTER_VALIDATE_BOOLEAN)) {
      $query->where('is_popular', true);
    }
    if (array_key_exists('is_free', $filters) && $filters['is_free'] !== null && $filters['is_free'] !== '') {
      $query->where('is_free', filter_var($filters['is_free'], FILTER_VALIDATE_BOOLEAN));
    }
    if (! empty($filters['language_id'])) {
      $languageId = CourseLanguage::query()->where('uuid', $filters['language_id'])->value('id');
      if ($languageId) {
        $query->where('language_id', $languageId);
      }
    }
    if (! empty($filters['level_id'])) {
      $levelId = CourseLevel::query()->where('uuid', $filters['level_id'])->value('id');
      if ($levelId) {
        $query->where('level_id', $levelId);
      }
    }
    if (! empty($filters['instructor_id'])) {
      $instructorId = Instructor::query()->where('uuid', $filters['instructor_id'])->value('id');
      if ($instructorId) {
        $query->whereHas('instructors', fn ($q) => $q->where('instructors.id', $instructorId));
      }
    }
    if (! empty($filters['ministry_id'])) {
      $ministryId = \App\Modules\Cms\Models\CmsMinistry::query()->where('uuid', $filters['ministry_id'])->value('id');
      if ($ministryId) {
        $query->where(function ($q) use ($ministryId): void {
          $q->where('primary_ministry_id', $ministryId)
            ->orWhereHas('ministries', fn ($mq) => $mq->where('cms_ministries.id', $ministryId));
        });
      }
    }

    $query->where(function ($q) use ($memberMinistryIds): void {
      $q->where('access_scope', 'general')
        ->orWhere(function ($inner) use ($memberMinistryIds): void {
          $inner->where('access_scope', 'ministry');
          if ($memberMinistryIds === []) {
            $inner->whereRaw('1 = 0');
          } else {
            $inner->where(function ($m) use ($memberMinistryIds): void {
              $m->whereIn('primary_ministry_id', $memberMinistryIds)
                ->orWhereHas('ministries', fn ($mq) => $mq->whereIn('cms_ministries.id', $memberMinistryIds));
            });
          }
        });
    });

    $sort = (string) ($filters['sort'] ?? 'latest');
    match ($sort) {
      'title' => $query->orderBy('title'),
      'popular' => $query->orderByDesc('enrollment_count'),
      'rating' => $query->orderByDesc('average_rating'),
      default => $query->orderByDesc('published_at')->orderByDesc('created_at'),
    };

    return $query->paginate(min(100, max(1, (int) ($filters['per_page'] ?? 25))));
  }

  public function findPublicBySlug(string $slug): ?Course
  {
    return Course::query()
      ->forPublicListing()
      ->where('slug', $slug)
      ->with([
        'category', 'level', 'language', 'coverMedia', 'trailerMedia',
        'instructors.photoMedia', 'tags', 'faqs',
        'modules' => fn ($q) => $q->where('status', 'published')->orderBy('sort_order'),
        'modules.lessons' => fn ($q) => $q->where('status', 'published')->orderBy('sort_order'),
        'modules.lessons.resources',
        'downloads' => fn ($q) => $q->where('is_public', true),
        'reviews' => fn ($q) => $q->where('status', 'approved')->latest()->limit(20),
      ])
      ->first();
  }

  /**
   * @param  array<string, mixed>  $data
   * @return array<string, mixed>
   */
  private function normalizePayload(array $data): array
  {
    $payload = collect($data)->only([
      'title', 'slug', 'course_code', 'subtitle', 'summary', 'description',
      'requirements', 'learning_objectives', 'status', 'access_scope', 'audience', 'difficulty',
      'is_featured', 'is_popular', 'is_recommended', 'sort_order', 'trailer_youtube_url', 'youtube_playlist_url',
      'member_price', 'public_price', 'is_free', 'visitor_free', 'member_free', 'promotional_price',
      'promotional_starts_at', 'promotional_ends_at', 'currency',
      'duration_minutes', 'estimated_completion_minutes', 'seo_title', 'seo_description', 'seo_keywords', 'metadata',
      'certificate_enabled', 'certificate_requires_assessment_pass',
      'certificate_min_score', 'certificate_min_completion_percent', 'certificate_auto_issue',
      'assessment_required', 'assignment_required', 'passing_score', 'max_attempts', 'completion_rule',
      'scheduled_publish_at',
    ])->all();

    if (! empty($data['category_id'])) {
      $payload['category_id'] = CourseCategory::query()->where('uuid', $data['category_id'])->value('id');
    } elseif (array_key_exists('category_id', $data) && $data['category_id'] === null) {
      $payload['category_id'] = null;
    }

    if (! empty($data['subcategory_id'])) {
      $payload['subcategory_id'] = CourseCategory::query()->where('uuid', $data['subcategory_id'])->value('id');
    } elseif (array_key_exists('subcategory_id', $data) && $data['subcategory_id'] === null) {
      $payload['subcategory_id'] = null;
    }

    if (! empty($data['level_id'])) {
      $payload['level_id'] = CourseLevel::query()->where('uuid', $data['level_id'])->value('id');
    }
    if (! empty($data['language_id'])) {
      $payload['language_id'] = CourseLanguage::query()->where('uuid', $data['language_id'])->value('id');
    }
    if (array_key_exists('cover_media_id', $data)) {
      $payload['cover_media_id'] = $data['cover_media_id']
        ? CmsMedia::query()->where('uuid', $data['cover_media_id'])->value('id')
        : null;
    }
    if (array_key_exists('thumbnail_media_id', $data)) {
      $payload['thumbnail_media_id'] = $data['thumbnail_media_id']
        ? CmsMedia::query()->where('uuid', $data['thumbnail_media_id'])->value('id')
        : null;
    }
    if (array_key_exists('banner_media_id', $data)) {
      $payload['banner_media_id'] = $data['banner_media_id']
        ? CmsMedia::query()->where('uuid', $data['banner_media_id'])->value('id')
        : null;
    }
    if (array_key_exists('trailer_media_id', $data)) {
      $payload['trailer_media_id'] = $data['trailer_media_id']
        ? CmsMedia::query()->where('uuid', $data['trailer_media_id'])->value('id')
        : null;
    }
    if (array_key_exists('certificate_template_id', $data)) {
      $payload['certificate_template_id'] = $data['certificate_template_id']
        ? \App\Modules\Lms\Models\CertificateTemplate::query()->where('uuid', $data['certificate_template_id'])->value('id')
        : null;
    }
    if (array_key_exists('primary_ministry_id', $data)) {
      $payload['primary_ministry_id'] = $data['primary_ministry_id']
        ? \App\Modules\Cms\Models\CmsMinistry::query()->where('uuid', $data['primary_ministry_id'])->value('id')
        : null;
    }

    return $payload;
  }

  /**
   * @param  array<string, mixed>  $data
   */
  private function syncRelations(Course $course, array $data): void
  {
    if (isset($data['tag_ids']) && is_array($data['tag_ids'])) {
      $tagIds = \App\Modules\Lms\Models\CourseTag::query()
        ->whereIn('uuid', $data['tag_ids'])
        ->pluck('id');
      $course->tags()->sync($tagIds);
    }

    if (isset($data['instructors']) && is_array($data['instructors'])) {
      $sync = [];
      foreach ($data['instructors'] as $index => $row) {
        $instructorId = Instructor::query()->where('uuid', $row['id'] ?? $row['instructor_id'] ?? null)->value('id');
        if (! $instructorId) {
          continue;
        }
        $sync[$instructorId] = [
          'is_primary' => (bool) ($row['is_primary'] ?? $index === 0),
          'sort_order' => (int) ($row['sort_order'] ?? $index),
          'role_label' => $row['role_label'] ?? null,
        ];
      }
      $course->instructors()->sync($sync);
    }

    if (isset($data['ministry_ids']) && is_array($data['ministry_ids'])) {
      $ministryIds = \App\Modules\Cms\Models\CmsMinistry::query()
        ->whereIn('uuid', $data['ministry_ids'])
        ->pluck('id');
      $course->ministries()->sync($ministryIds);
    }
  }

  private function nextCourseCode(): string
  {
    $next = ((int) Course::query()->withTrashed()->max('id')) + 1;

    return sprintf('KC-%05d', max(1, $next));
  }

  private function uniqueSlug(string $slug, ?int $ignoreId = null): string
  {
    $base = Str::slug($slug) ?: 'course';
    $candidate = $base;
    $i = 1;
    while (
      Course::query()
        ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
        ->where('slug', $candidate)
        ->exists()
    ) {
      $candidate = $base.'-'.$i;
      $i++;
    }

    return $candidate;
  }
}
