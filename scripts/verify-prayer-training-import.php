<?php

declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Modules\Lms\Models\Assessment;
use App\Modules\Lms\Models\Course;
use App\Modules\Lms\Models\Lesson;
use App\Modules\Lms\Services\PrayerTrainingImportService;
use PhpOffice\PhpSpreadsheet\IOFactory;

$path = $argv[1] ?? database_path('imports/Prayer Training.xlsx');
$book = IOFactory::load($path);
$rows = $book->getActiveSheet()->toArray(null, true, true, true);

$expectedLessons = [];
$expectedModules = 0;
$lastDataRow = null;
$moduleIndex = 0;

foreach ($rows as $rowIndex => $row) {
  $a = trim((string) ($row['A'] ?? ''));
  $b = trim((string) ($row['B'] ?? ''));
  if ($a === '' && $b === '') {
    continue;
  }
  if ($lastDataRow !== null && ((int) $rowIndex - $lastDataRow) > 1) {
    $moduleIndex++;
  } elseif ($lastDataRow === null) {
    $moduleIndex = 1;
  }
  if ($moduleIndex === 0) {
    $moduleIndex = 1;
  }
  $expectedModules = max($expectedModules, $moduleIndex);

  if (preg_match('/\b(exam|exams)\b/i', $a) && $b === '') {
    $lastDataRow = (int) $rowIndex;
    continue;
  }

  if ($a !== '' && $b !== '') {
    $expectedLessons[] = [
      'row' => (int) $rowIndex,
      'module' => $moduleIndex,
      'title' => $a,
      'url' => $b,
    ];
    $lastDataRow = (int) $rowIndex;
  }
}

$course = Course::query()->where('slug', PrayerTrainingImportService::COURSE_SLUG)->first();
if (! $course) {
  echo "FAIL: course not found\n";
  exit(1);
}

$moduleCount = $course->modules()->count();
$lessonCount = Lesson::query()->where('course_id', $course->id)->count();
$assessmentCount = Assessment::query()->where('course_id', $course->id)->count();
$duplicateSlugs = Lesson::query()
  ->where('course_id', $course->id)
  ->selectRaw('slug, COUNT(*) as c')
  ->groupBy('slug')
  ->having('c', '>', 1)
  ->count();

echo "Course: {$course->title} ({$course->slug})\n";
echo "Status: {$course->status->value}\n";
echo "Ministry ID: ".($course->primary_ministry_id ?? 'null')."\n";
echo "Ministry assignment: ".($course->metadata['ministry_assignment'] ?? 'n/a')."\n";
echo "Modules: {$moduleCount} (expected {$expectedModules})\n";
echo "Lessons: {$lessonCount} (expected ".count($expectedLessons).")\n";
echo "Assessments: {$assessmentCount} (expected 1)\n";
echo "Duplicate lesson slugs: {$duplicateSlugs}\n\n";

$failures = 0;
foreach ($expectedLessons as $expected) {
  $lesson = Lesson::query()
    ->where('course_id', $course->id)
    ->where('title', $expected['title'])
    ->first();

  if (! $lesson) {
    echo "FAIL row {$expected['row']}: lesson not found — {$expected['title']}\n";
    $failures++;
    continue;
  }

  $module = $course->modules()->where('id', $lesson->module_id)->first();
  $moduleNum = $module ? (int) preg_replace('/\D/', '', (string) $module->slug) : 0;

  if ($moduleNum !== $expected['module']) {
    echo "FAIL row {$expected['row']}: module {$moduleNum} != expected {$expected['module']} — {$expected['title']}\n";
    $failures++;
  }

  if ((string) $lesson->youtube_url !== $expected['url']) {
    echo "FAIL row {$expected['row']}: URL mismatch\n";
    echo "  expected: {$expected['url']}\n";
    echo "  actual:   {$lesson->youtube_url}\n";
    $failures++;
  }

  if (empty($lesson->youtube_video_id)) {
    echo "FAIL row {$expected['row']}: missing youtube_video_id — {$expected['title']}\n";
    $failures++;
  }
}

if ($failures === 0 && $moduleCount === $expectedModules && $lessonCount === count($expectedLessons) && $duplicateSlugs === 0) {
  echo "PASS: All spreadsheet lessons verified in database.\n";
  exit(0);
}

echo "\nVerification completed with {$failures} failure(s).\n";
exit(1);
