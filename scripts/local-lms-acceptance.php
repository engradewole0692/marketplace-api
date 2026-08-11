<?php

declare(strict_types=1);

/**
 * Local acceptance verification via Laravel services + HTTP to running artisan serve.
 * Does not re-import; uses existing Prayer Training records.
 */
require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\User;
use App\Modules\Lms\Enums\CourseStatus;
use App\Modules\Lms\Enums\LearnerType;
use App\Modules\Lms\Models\Course;
use App\Modules\Lms\Models\Lesson;
use App\Modules\Lms\Models\LessonProgress;
use App\Modules\Lms\Services\CourseService;
use App\Modules\Lms\Services\EnrollmentService;
use App\Modules\Lms\Services\PrayerTrainingImportService;
use App\Modules\Lms\Services\ProgressService;
use Illuminate\Support\Facades\Http;

function check(bool $ok, string $passMsg, string $failMsg): bool
{
    if ($ok) {
        echo "PASS: {$passMsg}\n";

        return true;
    }

    echo "FAIL: {$failMsg}\n";

    return false;
}

echo "=== LOCAL ACCEPTANCE (services + HTTP) ===\n\n";

$errors = 0;

$course = Course::query()->where('slug', PrayerTrainingImportService::COURSE_SLUG)->first();
if (! $course) {
    echo "FAIL: Prayer Training course missing\n";
    exit(1);
}

$admin = User::query()->whereHas('roles', fn ($q) => $q->where('slug', 'super_administrator'))->first();
if (! $admin) {
    echo "FAIL: Super admin missing\n";
    exit(1);
}

$wasDraft = $course->status === CourseStatus::Draft;
if ($wasDraft) {
    app(CourseService::class)->publish($course->fresh(), $admin);
    $course->refresh();
}

if (! check($course->status === CourseStatus::Published, 'Course published', 'Course not published')) {
    $errors++;
}

$public = app(CourseService::class)->findPublicBySlug('prayer-training');
if (! check($public !== null, 'Public course lookup', 'Public lookup null')) {
    $errors++;
}

if ($public) {
    $moduleCount = $public->modules->count();
    $lessonCount = $public->modules->sum(fn ($m) => $m->lessons->count());
    if (! check($moduleCount === 13, 'Public API modules: 13', "Public modules: {$moduleCount}")) {
        $errors++;
    }
    if (! check($lessonCount === 24, 'Public API lessons: 24', "Public lessons: {$lessonCount}")) {
        $errors++;
    }
}

$response = Http::get('http://127.0.0.1:8000/api/v1/public/courses/prayer-training');
if (! check($response->successful(), 'HTTP GET public course 200', 'HTTP public course '.$response->status())) {
    $errors++;
}

$learner = User::factory()->create();
$memberRole = \App\Models\Role::query()->where('slug', 'member')->first();
if ($memberRole) {
    $learner->roles()->sync([$memberRole->id]);
}

$enrollment = app(EnrollmentService::class)->enroll($course, $learner, LearnerType::Member);
if (! check($enrollment->status->value === 'active', 'Enrollment active', 'Enrollment '.$enrollment->status->value)) {
    $errors++;
}

$second = app(EnrollmentService::class)->enroll($course, $learner, LearnerType::Member);
if (! check($second->id === $enrollment->id, 'No duplicate enrollment', 'Duplicate enrollment created')) {
    $errors++;
}

$lesson1 = Lesson::query()->where('course_id', $course->id)->where('slug', 'lesson-1')->first();
if ($lesson1) {
    app(ProgressService::class)->markLessonProgress($enrollment, $lesson1, 100);
    $progress = LessonProgress::query()
        ->where('enrollment_id', $enrollment->id)
        ->where('lesson_id', $lesson1->id)
        ->first();
    if (! check($progress !== null, 'Progress persisted', 'Progress missing')) {
        $errors++;
    }
}

app(CourseService::class)->unpublish($course->fresh(), $admin);
$course->refresh();
if (! check($course->status === CourseStatus::Draft, 'Course unpublished (restored draft)', 'Unpublish failed')) {
    $errors++;
}

if (! check(Http::get('http://127.0.0.1:8000/api/v1/public/courses/prayer-training')->status() === 404, 'HTTP 404 after unpublish', 'Expected 404 after unpublish')) {
    $errors++;
}

echo "\n=== ERRORS: {$errors} ===\n";
exit($errors > 0 ? 1 : 0);
