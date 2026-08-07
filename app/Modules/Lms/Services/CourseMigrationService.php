<?php

declare(strict_types=1);

namespace App\Modules\Lms\Services;

use App\Contracts\ServiceContract;
use App\Modules\Cms\Enums\CatalogItemType;
use App\Modules\Cms\Models\CmsCatalogItem;
use App\Modules\Cms\Models\CmsLeadershipProfile;
use App\Modules\Cms\Models\CmsMedia;
use App\Modules\Lms\Data\LegacyCourseLibrary;
use App\Modules\Lms\Enums\AssessmentStatus;
use App\Modules\Lms\Enums\AssessmentType;
use App\Modules\Lms\Enums\CatalogStatus;
use App\Modules\Lms\Enums\CourseStatus;
use App\Modules\Lms\Enums\LessonType;
use App\Modules\Lms\Enums\ModuleStatus;
use App\Modules\Lms\Enums\QuestionType;
use App\Modules\Lms\Enums\VideoSource;
use App\Modules\Lms\Models\Assessment;
use App\Modules\Lms\Models\CertificateTemplate;
use App\Modules\Lms\Models\Course;
use App\Modules\Lms\Models\CourseCategory;
use App\Modules\Lms\Models\CourseDownload;
use App\Modules\Lms\Models\CourseModule;
use App\Modules\Lms\Models\Instructor;
use App\Modules\Lms\Models\Lesson;
use App\Modules\Lms\Models\Question;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Idempotent import of the existing YouTube / CMS catalog into LMS.
 * Never rebuilds courses that already exist by slug. Never re-uploads CmsMedia.
 */
final class CourseMigrationService implements ServiceContract
{
  /** @var array<string, int> */
  private array $stats = [
    'instructors_created' => 0,
    'instructors_reused' => 0,
    'categories_created' => 0,
    'categories_reused' => 0,
    'courses_created' => 0,
    'courses_skipped_existing' => 0,
    'modules_created' => 0,
    'lessons_created' => 0,
    'downloads_created' => 0,
    'downloads_reused' => 0,
    'media_links_reused' => 0,
    'media_uploads' => 0,
    'catalog_vlogs_synced' => 0,
    'assessments_ensured' => 0,
    'certificates_enabled' => 0,
  ];

  /** @var list<string> */
  private array $notes = [];

  /**
   * @return array{stats: array<string, int>, notes: list<string>, course_slugs: list<string>}
   */
  public function migrate(bool $dryRun = false): array
  {
    $this->resetCounters();

    if ($dryRun) {
      return $this->preview();
    }

    return DB::transaction(function (): array {
      $this->ensureDefaultCertificateTemplate();
      $instructors = $this->importInstructors();
      $this->importCategories();
      $this->syncCatalogVlogs();
      $courseSlugs = [];

      foreach (LegacyCourseLibrary::playlists() as $playlist) {
        $courseSlugs[] = $this->importPlaylistCourse($playlist, $instructors);
      }

      $courseSlugs[] = $this->importResourcesCourse($instructors);

      return [
        'stats' => $this->stats,
        'notes' => $this->notes,
        'course_slugs' => array_values(array_unique($courseSlugs)),
      ];
    });
  }

  /**
   * @return array{stats: array<string, int>, notes: list<string>, course_slugs: list<string>}
   */
  private function preview(): array
  {
    $existing = Course::query()
      ->whereIn('slug', array_merge(
        array_column(LegacyCourseLibrary::playlists(), 'slug'),
        [LegacyCourseLibrary::resourcesCourseSlug()],
      ))
      ->pluck('slug')
      ->all();

    $planned = array_merge(
      array_column(LegacyCourseLibrary::playlists(), 'slug'),
      [LegacyCourseLibrary::resourcesCourseSlug()],
    );

    foreach ($planned as $slug) {
      if (in_array($slug, $existing, true)) {
        $this->stats['courses_skipped_existing']++;
        $this->notes[] = "Would skip existing course: {$slug}";
      } else {
        $this->stats['courses_created']++;
        $this->notes[] = "Would create course: {$slug}";
      }
    }

    $this->stats['media_uploads'] = 0;
    $this->notes[] = 'Dry run: no database writes. Media uploads always remain 0 (reuse Media Library only).';

    return [
      'stats' => $this->stats,
      'notes' => $this->notes,
      'course_slugs' => $planned,
    ];
  }

  private function resetCounters(): void
  {
    foreach (array_keys($this->stats) as $key) {
      $this->stats[$key] = 0;
    }
    $this->notes = [];
  }

  /**
   * @return array<string, Instructor>
   */
  private function importInstructors(): array
  {
    $map = [];
    $profiles = CmsLeadershipProfile::query()
      ->where('is_active', true)
      ->orderBy('sort_order')
      ->get();

    if ($profiles->isEmpty()) {
      $instructor = Instructor::query()->firstOrCreate(
        ['slug' => 'tribe-faculty'],
        [
          'name' => 'Tribe Faculty',
          'title' => 'Marketplace Ministers',
          'bio' => 'Instructors drawn from the Tribe leadership council.',
          'status' => CatalogStatus::Active,
          'metadata' => ['source' => 'm6g_migration'],
        ],
      );
      $this->bump($instructor->wasRecentlyCreated ? 'instructors_created' : 'instructors_reused');
      $map['tribe-faculty'] = $instructor;

      return $map;
    }

    foreach ($profiles as $profile) {
      $existing = Instructor::query()->where('slug', $profile->slug)->first();
      if ($existing) {
        $this->stats['instructors_reused']++;
        if ($existing->photo_media_id === null && $profile->photo_media_id) {
          $existing->update(['photo_media_id' => $profile->photo_media_id]);
          $this->stats['media_links_reused']++;
        }
        $map[$profile->slug] = $existing;
        continue;
      }

      $instructor = Instructor::query()->create([
        'name' => $profile->name,
        'slug' => $profile->slug,
        'title' => $profile->role,
        'bio' => $profile->bio,
        'photo_media_id' => $profile->photo_media_id,
        'email' => $profile->email,
        'status' => CatalogStatus::Active,
        'metadata' => [
          'source' => 'cms_leadership',
          'leadership_slug' => $profile->slug,
          'location' => $profile->location,
        ],
      ]);
      $this->stats['instructors_created']++;
      if ($profile->photo_media_id) {
        $this->stats['media_links_reused']++;
      }
      $map[$profile->slug] = $instructor;
    }

    return $map;
  }

  private function importCategories(): void
  {
    foreach (LegacyCourseLibrary::categories() as $index => $name) {
      $slug = Str::slug($name);
      $existing = CourseCategory::query()->where('slug', $slug)->first();
      if ($existing) {
        $this->stats['categories_reused']++;
        continue;
      }
      CourseCategory::query()->create([
        'name' => $name,
        'slug' => $slug,
        'description' => "Migrated category: {$name}",
        'sort_order' => $index + 1,
        'status' => CatalogStatus::Active,
      ]);
      $this->stats['categories_created']++;
    }
  }

  private function syncCatalogVlogs(): void
  {
    $channel = LegacyCourseLibrary::channelUrl();

    foreach (LegacyCourseLibrary::videos() as $index => $video) {
      $coverId = $this->resolveCoverMediaId($video['cover_asset'], $video['slug']);
      $youtubeUrl = $this->youtubeUrlFor($video['youtube_id'], $channel);

      CmsCatalogItem::query()->updateOrCreate(
        ['type' => CatalogItemType::Vlog, 'slug' => $video['slug']],
        [
          'title' => $video['title'],
          'summary' => $video['description'],
          'body' => $video['description'],
          'category' => $video['category'],
          'tags' => $video['playlist_ids'],
          'featured_media_id' => $coverId,
          'is_active' => true,
          'is_featured' => (bool) ($video['featured'] ?? false),
          'sort_order' => $index + 1,
          'published_at' => $video['published_at'],
          'metadata' => [
            'source' => 'm6g_migration',
            'legacy_id' => $video['id'],
            'youtube_id' => $video['youtube_id'],
            'youtube_url' => $youtubeUrl,
            'channel_url' => $channel,
            'duration' => $video['duration'],
            'duration_seconds' => $video['duration_seconds'],
            'view_count' => $video['view_count'],
            'playlist_ids' => $video['playlist_ids'],
            'cover_asset' => $video['cover_asset'],
          ],
        ],
      );
      $this->stats['catalog_vlogs_synced']++;
      if ($coverId) {
        $this->stats['media_links_reused']++;
      }
    }
  }

  /**
   * @param  array{id: string, slug: string, title: string, description: string, cover_asset: string, video_ids: list<string>}  $playlist
   * @param  array<string, Instructor>  $instructors
   */
  private function importPlaylistCourse(array $playlist, array $instructors): string
  {
    $existing = Course::query()->where('slug', $playlist['slug'])->first();
    if ($existing) {
      $this->stats['courses_skipped_existing']++;
      $this->notes[] = "Skipped rebuild of existing course: {$playlist['slug']}";
      $this->enrichExistingCourse($existing, $instructors);

      return $playlist['slug'];
    }

    $videosById = collect(LegacyCourseLibrary::videos())->keyBy('id');
    $videos = [];
    foreach ($playlist['video_ids'] as $videoId) {
      if ($videosById->has($videoId)) {
        $videos[] = $videosById->get($videoId);
      }
    }

    $primaryCategory = $videos[0]['category'] ?? 'Teachings';
    $category = CourseCategory::query()->where('slug', Str::slug($primaryCategory))->first();
    $coverId = $this->resolveCoverMediaId($playlist['cover_asset'], $playlist['slug']);
    $duration = (int) collect($videos)->sum(fn (array $v): int => (int) ceil(($v['duration_seconds'] ?? 0) / 60));

    $course = Course::query()->create([
      'category_id' => $category?->id,
      'title' => $playlist['title'],
      'slug' => $playlist['slug'],
      'subtitle' => 'Migrated from YouTube library',
      'summary' => $playlist['description'],
      'description' => $playlist['description'],
      'status' => CourseStatus::Published,
      'is_featured' => $playlist['slug'] === 'foundations',
      'is_free' => true,
      'certificate_enabled' => true,
      'certificate_requires_assessment_pass' => true,
      'cover_media_id' => $coverId,
      'trailer_youtube_url' => LegacyCourseLibrary::channelUrl(),
      'duration_minutes' => max(1, $duration),
      'published_at' => now(),
      'seo_title' => $playlist['title'],
      'seo_description' => $playlist['description'],
      'metadata' => [
        'source' => 'm6g_migration',
        'legacy_playlist_id' => $playlist['id'],
        'channel_url' => LegacyCourseLibrary::channelUrl(),
        'preserved_urls' => true,
      ],
    ]);
    $this->stats['courses_created']++;
    if ($coverId) {
      $this->stats['media_links_reused']++;
    }
    $this->stats['certificates_enabled']++;

    $this->attachPrimaryInstructor($course, $instructors);

    $module = CourseModule::query()->create([
      'course_id' => $course->id,
      'title' => $playlist['title'].' Sessions',
      'slug' => $playlist['slug'].'-sessions',
      'description' => $playlist['description'],
      'sort_order' => 1,
      'status' => ModuleStatus::Published,
      'duration_minutes' => max(1, $duration),
    ]);
    $this->stats['modules_created']++;

    foreach ($videos as $index => $video) {
      $this->createLessonFromVideo($course, $module, $video, $index + 1);
    }

    $this->ensureCourseAssessment($course);
    $this->attachMatchingResources($course);

    return $course->slug;
  }

  /**
   * @param  array<string, Instructor>  $instructors
   */
  private function importResourcesCourse(array $instructors): string
  {
    $slug = LegacyCourseLibrary::resourcesCourseSlug();
    $existing = Course::query()->where('slug', $slug)->first();
    if ($existing) {
      $this->stats['courses_skipped_existing']++;
      $this->notes[] = "Skipped rebuild of existing course: {$slug}";
      $this->syncResourceDownloads($existing);
      $this->enrichExistingCourse($existing, $instructors);

      return $slug;
    }

    $category = CourseCategory::query()->where('slug', 'resources')->first()
      ?? CourseCategory::query()->where('slug', 'formation')->first();

    $course = Course::query()->create([
      'category_id' => $category?->id,
      'title' => 'Kingdom Resources Library',
      'slug' => $slug,
      'subtitle' => 'Existing PDFs and downloadable resources',
      'summary' => 'Migrated catalog resources — PDFs, guides, and study materials from the Media Library.',
      'description' => 'All existing CMS catalog resources, linked to Media Library files without duplication.',
      'status' => CourseStatus::Published,
      'is_free' => true,
      'certificate_enabled' => true,
      'certificate_requires_assessment_pass' => true,
      'published_at' => now(),
      'metadata' => [
        'source' => 'm6g_migration',
        'kind' => 'resources_library',
      ],
    ]);
    $this->stats['courses_created']++;
    $this->stats['certificates_enabled']++;

    $this->attachPrimaryInstructor($course, $instructors);

    $module = CourseModule::query()->create([
      'course_id' => $course->id,
      'title' => 'Resource Orientation',
      'slug' => 'resource-orientation',
      'description' => 'How to use the migrated resource library.',
      'sort_order' => 1,
      'status' => ModuleStatus::Published,
    ]);
    $this->stats['modules_created']++;

    Lesson::query()->create([
      'module_id' => $module->id,
      'course_id' => $course->id,
      'title' => 'Welcome to the Resource Library',
      'slug' => 'welcome-resource-library',
      'summary' => 'Download existing PDFs and guides from the course downloads panel.',
      'content' => 'These materials were imported from the existing CMS catalog. Files reuse the Media Library — no duplicates were created.',
      'sort_order' => 1,
      'status' => ModuleStatus::Published,
      'lesson_type' => LessonType::Resource,
      'video_source' => VideoSource::None,
      'is_preview' => true,
      'is_mandatory' => true,
      'completion_threshold_percent' => 100,
    ]);
    $this->stats['lessons_created']++;

    $this->syncResourceDownloads($course);
    $this->ensureCourseAssessment($course);

    return $course->slug;
  }

  /**
   * @param  array<string, mixed>  $video
   */
  private function createLessonFromVideo(Course $course, CourseModule $module, array $video, int $sortOrder): void
  {
    $channel = LegacyCourseLibrary::channelUrl();
    $youtubeId = trim((string) ($video['youtube_id'] ?? ''));
    $youtubeUrl = $this->youtubeUrlFor($youtubeId, $channel);

    Lesson::query()->create([
      'module_id' => $module->id,
      'course_id' => $course->id,
      'title' => $video['title'],
      'slug' => $video['slug'],
      'summary' => $video['description'],
      'content' => $video['description'],
      'sort_order' => $sortOrder,
      'status' => ModuleStatus::Published,
      'lesson_type' => LessonType::Video,
      'is_preview' => $sortOrder === 1,
      'duration_minutes' => max(1, (int) ceil(($video['duration_seconds'] ?? 60) / 60)),
      'video_source' => VideoSource::Youtube,
      'youtube_video_id' => $youtubeId !== '' ? $youtubeId : null,
      'youtube_url' => $youtubeUrl,
      'is_mandatory' => true,
      'completion_threshold_percent' => 80,
    ]);
    $this->stats['lessons_created']++;

    if ($youtubeId === '') {
      $this->notes[] = "Lesson '{$video['slug']}' preserved without youtube_video_id (source catalog empty); channel URL retained.";
    }
  }

  private function syncResourceDownloads(Course $course): void
  {
    $resources = CmsCatalogItem::query()
      ->where('type', CatalogItemType::Resource)
      ->where('is_active', true)
      ->orderBy('sort_order')
      ->get();

    foreach ($resources as $index => $item) {
      $metadata = is_array($item->metadata) ? $item->metadata : [];
      $fileMediaId = isset($metadata['file_media_id']) ? (int) $metadata['file_media_id'] : null;
      if (! $fileMediaId || ! CmsMedia::query()->whereKey($fileMediaId)->exists()) {
        $fileMediaId = $this->resolveResourceFileMediaId($item->slug);
      }

      $download = CourseDownload::query()
        ->where('course_id', $course->id)
        ->where('title', $item->title)
        ->first();

      if ($download) {
        $this->stats['downloads_reused']++;
        if ($download->file_media_id === null && $fileMediaId) {
          $download->update([
            'file_media_id' => $fileMediaId,
            'external_url' => $metadata['download_url'] ?? $metadata['file_url'] ?? $download->external_url,
          ]);
          $this->stats['media_links_reused']++;
        }
        continue;
      }

      CourseDownload::query()->create([
        'course_id' => $course->id,
        'title' => $item->title,
        'description' => $item->summary,
        'file_media_id' => $fileMediaId,
        'external_url' => $metadata['download_url'] ?? $metadata['file_url'] ?? null,
        'sort_order' => $index + 1,
        'is_public' => ($metadata['access_level'] ?? 'free') === 'free',
      ]);
      $this->stats['downloads_created']++;
      if ($fileMediaId) {
        $this->stats['media_links_reused']++;
      }
    }
  }

  private function attachMatchingResources(Course $course): void
  {
    $resources = CmsCatalogItem::query()
      ->where('type', CatalogItemType::Resource)
      ->where('is_active', true)
      ->where(function ($q) use ($course): void {
        $q->where('category', 'like', '%'.$course->title.'%')
          ->orWhere('slug', 'like', '%'.$course->slug.'%')
          ->orWhere('title', 'like', '%'.$course->title.'%');
      })
      ->get();

    foreach ($resources as $index => $item) {
      if (CourseDownload::query()->where('course_id', $course->id)->where('title', $item->title)->exists()) {
        continue;
      }
      $metadata = is_array($item->metadata) ? $item->metadata : [];
      $fileMediaId = isset($metadata['file_media_id']) ? (int) $metadata['file_media_id'] : $this->resolveResourceFileMediaId($item->slug);
      CourseDownload::query()->create([
        'course_id' => $course->id,
        'title' => $item->title,
        'description' => $item->summary,
        'file_media_id' => $fileMediaId,
        'external_url' => $metadata['download_url'] ?? $metadata['file_url'] ?? null,
        'sort_order' => $index + 1,
        'is_public' => true,
      ]);
      $this->stats['downloads_created']++;
      if ($fileMediaId) {
        $this->stats['media_links_reused']++;
      }
    }
  }

  /**
   * @param  array<string, Instructor>  $instructors
   */
  private function enrichExistingCourse(Course $course, array $instructors): void
  {
    $updates = [];
    if (! $course->certificate_enabled) {
      $updates['certificate_enabled'] = true;
      $this->stats['certificates_enabled']++;
    }
    if ($course->summary === null && $course->description) {
      $updates['summary'] = $course->description;
    }
    if ($updates !== []) {
      $course->update($updates);
    }

    if ($course->instructors()->count() === 0) {
      $this->attachPrimaryInstructor($course, $instructors);
    }

    $this->ensureCourseAssessment($course);
  }

  /**
   * @param  array<string, Instructor>  $instructors
   */
  private function attachPrimaryInstructor(Course $course, array $instructors): void
  {
    $primary = $instructors['damola-adelakun']
      ?? $instructors['jesse-jangfa']
      ?? reset($instructors)
      ?: null;

    if (! $primary instanceof Instructor) {
      return;
    }

    if ($course->instructors()->where('lms_instructors.id', $primary->id)->exists()) {
      return;
    }

    $course->instructors()->attach($primary->id, [
      'is_primary' => true,
      'sort_order' => 0,
      'role_label' => $primary->title,
    ]);
  }

  private function ensureCourseAssessment(Course $course): void
  {
    $exists = Assessment::query()->where('course_id', $course->id)->exists();
    if ($exists) {
      return;
    }

    $question = Question::query()->firstOrCreate(
      ['prompt' => 'Migrated course check: Marketplace ministry is a Kingdom calling.'],
      [
        'stem' => 'Confirm foundational understanding after completing this migrated course.',
        'question_type' => QuestionType::TrueFalse,
        'default_points' => 1,
        'status' => 'active',
        'explanation' => 'Marketplace ministry is a Kingdom calling.',
        'metadata' => ['source' => 'm6g_migration'],
      ],
    );

    if ($question->options()->count() === 0) {
      $question->options()->create(['label' => 'T', 'body' => 'True', 'is_correct' => true, 'sort_order' => 0]);
      $question->options()->create(['label' => 'F', 'body' => 'False', 'is_correct' => false, 'sort_order' => 1]);
    }

    $assessment = Assessment::query()->create([
      'course_id' => $course->id,
      'title' => $course->title.' — Completion Check',
      'slug' => $course->slug.'-completion-check',
      'description' => 'Lightweight assessment attached during M6-G migration to verify the assessment path.',
      'assessment_type' => AssessmentType::Quiz,
      'status' => AssessmentStatus::Published,
      'pass_mark' => 70,
      'max_attempts' => 5,
      'show_immediate_result' => true,
      'allow_review' => true,
      'settings' => ['source' => 'm6g_migration'],
    ]);
    $assessment->questions()->sync([
      $question->id => ['points' => 1, 'sort_order' => 0],
    ]);
    $this->stats['assessments_ensured']++;
  }

  private function ensureDefaultCertificateTemplate(): void
  {
    CertificateTemplate::query()->firstOrCreate(
      ['slug' => 'migrated-default'],
      [
        'name' => 'Migrated Course Certificate',
        'html_body' => '<h1>Certificate of Completion</h1><p>{{learner_name}} completed {{course_title}}</p><p>Code: {{verification_code}}</p>',
        'is_active' => true,
        'is_default' => true,
        'sort_order' => 0,
      ],
    );
  }

  private function resolveCoverMediaId(string $assetKey, string $slug): ?int
  {
    $byMeta = CmsMedia::query()
      ->where('metadata->seed_asset', $assetKey)
      ->value('id');
    if ($byMeta) {
      return (int) $byMeta;
    }

    $bySlug = CmsMedia::query()
      ->where('path', 'like', '%/'.$slug.'.%')
      ->value('id');
    if ($bySlug) {
      return (int) $bySlug;
    }

    $byAsset = CmsMedia::query()
      ->where('path', 'like', '%'.$assetKey.'%')
      ->value('id');

    return $byAsset ? (int) $byAsset : null;
  }

  private function resolveResourceFileMediaId(string $slug): ?int
  {
    $id = CmsMedia::query()
      ->where('path', 'like', "%resource-files/{$slug}.%")
      ->value('id');

    return $id ? (int) $id : null;
  }

  private function youtubeUrlFor(string $youtubeId, string $channel): string
  {
    if ($youtubeId !== '') {
      return 'https://www.youtube.com/watch?v='.$youtubeId;
    }

    return $channel;
  }

  private function bump(string $key): void
  {
    $this->stats[$key] = ($this->stats[$key] ?? 0) + 1;
  }
}
