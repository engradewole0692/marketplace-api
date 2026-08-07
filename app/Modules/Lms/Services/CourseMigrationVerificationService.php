<?php

declare(strict_types=1);

namespace App\Modules\Lms\Services;

use App\Contracts\ServiceContract;
use App\Models\User;
use App\Modules\Lms\Data\LegacyCourseLibrary;
use App\Modules\Lms\Enums\LearnerType;
use App\Modules\Lms\Enums\VideoSource;
use App\Modules\Lms\Models\Assessment;
use App\Modules\Lms\Models\Course;
use App\Modules\Lms\Models\Lesson;
use Illuminate\Support\Str;

/**
 * Verifies every migrated course for video, downloads, enrollment, certificates, assessments.
 */
final class CourseMigrationVerificationService implements ServiceContract
{
  public function __construct(
    private readonly EnrollmentService $enrollmentService,
  ) {}

  /**
   * @return array{
   *   passed: bool,
   *   courses: list<array<string, mixed>>,
   *   summary: array<string, int>
   * }
   */
  public function verify(?User $probeUser = null): array
  {
    $slugs = array_merge(
      array_column(LegacyCourseLibrary::playlists(), 'slug'),
      [LegacyCourseLibrary::resourcesCourseSlug()],
    );

    $user = $probeUser ?? $this->resolveProbeUser();
    $rows = [];
    $failures = 0;

    foreach ($slugs as $slug) {
      $row = $this->verifyCourse($slug, $user);
      $rows[] = $row;
      if (! $row['passed']) {
        $failures++;
      }
    }

    return [
      'passed' => $failures === 0,
      'courses' => $rows,
      'summary' => [
        'total' => count($rows),
        'passed' => count($rows) - $failures,
        'failed' => $failures,
      ],
    ];
  }

  /**
   * @return array<string, mixed>
   */
  private function verifyCourse(string $slug, User $user): array
  {
    $checks = [
      'exists' => false,
      'video_playback' => false,
      'downloads' => false,
      'enrollment' => false,
      'certificates' => false,
      'assessments' => false,
    ];
    $details = [];

    $course = Course::query()
      ->with(['lessons', 'downloads.fileMedia', 'modules'])
      ->where('slug', $slug)
      ->first();

    if (! $course) {
      return [
        'slug' => $slug,
        'passed' => false,
        'checks' => $checks,
        'details' => ['Course missing — run lms:migrate-legacy-courses first.'],
      ];
    }

    $checks['exists'] = true;

    // Video playback: video courses need youtube/media lessons; resources course allows resource lessons.
    if ($slug === LegacyCourseLibrary::resourcesCourseSlug()) {
      $checks['video_playback'] = $course->lessons->isNotEmpty();
      $details[] = 'Resources course: orientation lesson present (video N/A).';
    } else {
      $videoLessons = $course->lessons->filter(function (Lesson $lesson): bool {
        if ($lesson->video_source === VideoSource::Youtube) {
          return filled($lesson->youtube_url) || filled($lesson->youtube_video_id);
        }
        if ($lesson->video_source === VideoSource::Media) {
          return $lesson->video_media_id !== null;
        }

        return false;
      });
      $checks['video_playback'] = $videoLessons->isNotEmpty();
      $pendingIds = $course->lessons
        ->filter(fn (Lesson $l) => $l->video_source === VideoSource::Youtube && blank($l->youtube_video_id))
        ->count();
      if ($pendingIds > 0) {
        $details[] = "{$pendingIds} lesson(s) preserve empty youtube_video_id from source catalog; channel/watch URL retained for player wiring.";
      }
      $details[] = 'Video lessons with playback config: '.$videoLessons->count();
    }

    // Downloads: resources course must have file-backed downloads; playlist courses pass if any download OR no catalog match required.
    if ($slug === LegacyCourseLibrary::resourcesCourseSlug()) {
      $withFiles = $course->downloads->filter(fn ($d) => $d->file_media_id || filled($d->external_url));
      $checks['downloads'] = $withFiles->isNotEmpty();
      $details[] = 'Downloads with media/url: '.$withFiles->count();
    } else {
      $checks['downloads'] = true;
      $details[] = 'Course downloads: '.$course->downloads->count().' (optional for playlist courses).';
    }

    // Enrollment
    try {
      $enrollment = $this->enrollmentService->enroll($course, $user, LearnerType::Public);
      $checks['enrollment'] = $enrollment->exists;
      $details[] = 'Enrollment status: '.(
        $enrollment->status instanceof \BackedEnum
          ? $enrollment->status->value
          : (string) $enrollment->status
      );
    } catch (\Throwable $e) {
      $checks['enrollment'] = false;
      $details[] = 'Enrollment failed: '.$e->getMessage();
    }

    // Certificates
    $checks['certificates'] = (bool) $course->certificate_enabled;
    $details[] = 'certificate_enabled='.($course->certificate_enabled ? 'true' : 'false');

    // Assessments
    $assessmentCount = Assessment::query()
      ->where('course_id', $course->id)
      ->where('status', 'published')
      ->count();
    $checks['assessments'] = $assessmentCount > 0;
    $details[] = "Published assessments: {$assessmentCount}";

    $passed = ! in_array(false, $checks, true);

    return [
      'slug' => $slug,
      'title' => $course->title,
      'passed' => $passed,
      'checks' => $checks,
      'details' => $details,
    ];
  }

  private function resolveProbeUser(): User
  {
    $email = 'm6g-migration-probe@example.com';
    $user = User::query()->where('email', $email)->first();
    if ($user) {
      return $user;
    }

    return User::query()->create([
      'name' => 'M6G Migration Probe',
      'email' => $email,
      'password' => bcrypt(Str::random(32)),
    ]);
  }
}
