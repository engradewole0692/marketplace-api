<?php

declare(strict_types=1);

/**
 * Local E2E API verification for Prayer Training LMS.
 * Usage: php scripts/local-e2e-prayer-training.php [base_url]
 */

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\User;
use App\Modules\Lms\Models\Assessment;
use App\Modules\Lms\Models\Course;
use App\Modules\Lms\Models\Enrollment;
use App\Modules\Lms\Models\Lesson;
use App\Modules\Lms\Services\CourseService;
use App\Modules\Lms\Services\PrayerTrainingImportService;
use Illuminate\Support\Facades\Http;

$base = rtrim($argv[1] ?? 'http://127.0.0.1:8000', '/');
$api = $base.'/api/v1';
$results = [];
$failures = 0;

function check(array &$results, int &$failures, string $name, bool $ok, string $detail = ''): void
{
    $results[] = ['name' => $name, 'ok' => $ok, 'detail' => $detail];
    if (! $ok) {
        $failures++;
        echo "FAIL: {$name}".($detail !== '' ? " — {$detail}" : '').PHP_EOL;
    } else {
        echo "PASS: {$name}".($detail !== '' ? " — {$detail}" : '').PHP_EOL;
    }
}

echo "=== Prayer Training Local E2E (API: {$api}) ===".PHP_EOL.PHP_EOL;

// 1. Health
$health = Http::get($api.'/health');
check($results, $failures, 'API health', $health->successful(), 'status '.$health->status());

// 2. DB course state
$course = Course::query()->where('slug', PrayerTrainingImportService::COURSE_SLUG)->first();
check($results, $failures, 'Course exists in DB', $course !== null);
if (! $course) {
    echo PHP_EOL."Aborting — no prayer-training course in database.".PHP_EOL;
    exit(1);
}

$moduleCount = $course->modules()->count();
$lessonCount = Lesson::query()->where('course_id', $course->id)->count();
$assessmentCount = Assessment::query()->where('course_id', $course->id)->count();

check($results, $failures, '13 modules in DB', $moduleCount === 13, "found {$moduleCount}");
check($results, $failures, '24 lessons in DB', $lessonCount === 24, "found {$lessonCount}");
check($results, $failures, '1 assessment in DB', $assessmentCount === 1, "found {$assessmentCount}");
check($results, $failures, 'Ministry unassigned', $course->primary_ministry_id === null);
check($results, $failures, 'Metadata ministry unassigned', ($course->metadata['ministry_assignment'] ?? null) === 'unassigned');

$lesson1 = Lesson::query()->where('course_id', $course->id)->where('slug', 'lesson-1')->first();
check(
    $results,
    $failures,
    'Lesson 1 title preserved',
    $lesson1 && $lesson1->title === '1 - Introduction to Prayer Leadership; What is Prayer',
);
check(
    $results,
    $failures,
    'Lesson 1 YouTube URL preserved',
    $lesson1 && str_contains((string) $lesson1->youtube_url, 'youtu.be/1TvQSTdiuQM'),
);

// 3. Idempotency dry-run (no writes)
$dryRun = app(PrayerTrainingImportService::class)->importFromPath(
    database_path('imports/Prayer Training.xlsx'),
    true,
);
check($results, $failures, 'Dry-run 13 modules', ($dryRun['stats']['modules_created'] ?? 0) === 13);
check($results, $failures, 'Dry-run 24 lessons', ($dryRun['stats']['lessons_created'] ?? 0) === 24);
check($results, $failures, 'Dry-run 0 failures', ($dryRun['stats']['rows_failed'] ?? 0) === 0);

// 4. Admin + publish flow
$admin = User::query()->whereHas('roles', fn ($q) => $q->where('slug', 'super_administrator'))->first()
    ?? User::query()->first();

check($results, $failures, 'Admin user available', $admin !== null);

$wasPublished = $course->status->value === 'published';
$originalStatus = $course->status;

if ($admin) {
    app(CourseService::class)->publish($course->fresh(), $admin);
    $course->refresh();
    check($results, $failures, 'Publish course (local test)', $course->status->value === 'published');

    $publicShow = Http::get($api.'/public/courses/prayer-training');
    check($results, $failures, 'Public course show', $publicShow->successful(), 'status '.$publicShow->status());
    check(
        $results,
        $failures,
        'Public course slug/title',
        $publicShow->json('data.course.slug') === 'prayer-training'
            && $publicShow->json('data.course.title') === 'Prayer Training',
    );

    $publicList = Http::get($api.'/public/courses?per_page=50');
    $slugs = collect($publicList->json('data.data') ?? [])->pluck('slug')->all();
    check($results, $failures, 'Public course listing includes prayer-training', in_array('prayer-training', $slugs, true));

    // Learner register + enroll
    $email = 'e2e-prayer-'.time().'@example.com';
    $register = Http::withHeaders(['Accept' => 'application/json'])
        ->post($api.'/learner/register', [
            'name' => 'E2E Prayer Learner',
            'email' => $email,
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
        ]);

    check($results, $failures, 'Learner registration', $register->successful() || $register->status() === 201, 'status '.$register->status());

    $login = Http::withHeaders(['Accept' => 'application/json'])
        ->post($api.'/auth/login', [
            'email' => $email,
            'password' => 'Password123!',
        ]);

    check($results, $failures, 'Learner login', $login->successful(), 'status '.$login->status());

    $token = $login->json('data.token') ?? $login->json('token');
    $authHeaders = ['Accept' => 'application/json'];
    if ($token) {
        $authHeaders['Authorization'] = 'Bearer '.$token;
    }

    $enroll = Http::withHeaders($authHeaders)->post($api.'/public/courses/prayer-training/enroll');
    check($results, $failures, 'Enrollment', $enroll->successful() || $enroll->status() === 201, 'status '.$enroll->status().' body '.$enroll->body());

    $enrollmentId = $enroll->json('data.enrollment.id') ?? $enroll->json('data.enrollment.uuid');
    $enrollment = Enrollment::query()
        ->where('course_id', $course->id)
        ->when($enrollmentId, fn ($q) => $q->where('uuid', $enrollmentId))
        ->latest('id')
        ->first();

    check($results, $failures, 'Enrollment persisted', $enrollment !== null);

    if ($enrollment && $lesson1) {
        $progress = Http::withHeaders($authHeaders)->post($api.'/learner/progress', [
            'enrollment_id' => $enrollment->uuid,
            'lesson_id' => $lesson1->uuid,
            'status' => 'completed',
            'progress_percent' => 100,
        ]);
        check($results, $failures, 'Lesson progress update', $progress->successful(), 'status '.$progress->status());

        $player = Http::withHeaders($authHeaders)->get($api.'/learner/player/'.$enrollment->uuid.'/'.$lesson1->uuid);
        check($results, $failures, 'Learner player payload', $player->successful(), 'status '.$player->status());
        check(
            $results,
            $failures,
            'Player includes YouTube lesson',
            str_contains((string) $player->body(), '1TvQSTdiuQM') || str_contains((string) $player->body(), 'youtube'),
        );
    }

    // Admin LMS endpoints
    $adminLogin = Http::withHeaders(['Accept' => 'application/json'])
        ->post($api.'/auth/login', [
            'email' => $admin->email,
            'password' => 'password',
        ]);

    $adminToken = $adminLogin->json('data.token') ?? null;
    $adminHeaders = ['Accept' => 'application/json'];
    if ($adminToken) {
        $adminHeaders['Authorization'] = 'Bearer '.$adminToken;
    }

    $adminCourse = Http::withHeaders($adminHeaders)->get($api.'/lms/courses/'.$course->uuid);
    check($results, $failures, 'Admin course show', $adminCourse->successful(), 'status '.$adminCourse->status());

    $importSchema = Http::withHeaders($adminHeaders)->get($api.'/lms/import/prayer-training/schema');
    check($results, $failures, 'Admin import schema', $importSchema->successful(), 'status '.$importSchema->status());

    // Unpublish restore if was draft
    if (! $wasPublished) {
        app(CourseService::class)->unpublish($course->fresh(), $admin);
        $course->refresh();
        check($results, $failures, 'Unpublish restored draft state', $course->status->value === 'draft');
    } else {
        check($results, $failures, 'Course left published (was already published)', true);
    }
}

echo PHP_EOL."=== Summary: ".count($results) - $failures.'/'.count($results)." passed ===".PHP_EOL;
exit($failures > 0 ? 1 : 0);
