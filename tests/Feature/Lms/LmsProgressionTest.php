<?php

declare(strict_types=1);

namespace Tests\Feature\Lms;

use App\Enums\MemberApprovalStatus;
use App\Models\Member;
use App\Models\User;
use App\Modules\Lms\Enums\CourseStatus;
use App\Modules\Lms\Enums\LessonType;
use App\Modules\Lms\Enums\SchoolStatus;
use App\Modules\Lms\Models\Course;
use App\Modules\Lms\Models\CourseCategory;
use App\Modules\Lms\Models\Enrollment;
use App\Modules\Lms\Models\Lesson;
use App\Modules\Lms\Models\LmsSchool;
use App\Modules\Lms\Models\SchoolEnrollment;
use App\Modules\Lms\Services\ProgramModuleService;
use Illuminate\Support\Str;
use Tests\Feature\Iam\IamTestCase;

final class LmsProgressionTest extends IamTestCase
{
  public function test_sequential_progression_locks_lessons_until_prior_complete(): void
  {
    $category = CourseCategory::query()->create([
      'name' => 'Progression',
      'slug' => 'progression',
      'status' => 'active',
    ]);

    $course = Course::query()->create([
      'category_id' => $category->id,
      'title' => 'Sequential Course',
      'slug' => 'sequential-course',
      'status' => CourseStatus::Published,
      'published_at' => now(),
      'is_free' => true,
      'metadata' => ['sequential_progression' => true],
    ]);

    $module = $course->modules()->create([
      'title' => 'Module One',
      'slug' => 'module-one',
      'status' => 'published',
      'sort_order' => 1,
    ]);

    $lessonOne = Lesson::query()->create([
      'module_id' => $module->id,
      'course_id' => $course->id,
      'title' => 'Lesson One',
      'slug' => 'lesson-one',
      'status' => 'published',
      'lesson_type' => LessonType::Video,
      'video_source' => 'youtube',
      'youtube_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
      'is_mandatory' => true,
      'completion_threshold_percent' => 100,
      'sort_order' => 1,
    ]);

    $lessonTwo = Lesson::query()->create([
      'module_id' => $module->id,
      'course_id' => $course->id,
      'title' => 'Lesson Two',
      'slug' => 'lesson-two',
      'status' => 'published',
      'lesson_type' => LessonType::Video,
      'video_source' => 'youtube',
      'youtube_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
      'is_mandatory' => true,
      'completion_threshold_percent' => 100,
      'sort_order' => 2,
    ]);

    $learner = $this->memberUser();

    $this->actingAs($learner)
      ->postJson('/api/v1/public/courses/sequential-course/enroll')
      ->assertCreated();

    $enrollment = Enrollment::query()->where('user_id', $learner->id)->where('course_id', $course->id)->firstOrFail();

    $this->actingAs($learner)
      ->getJson("/api/v1/learner/player/{$enrollment->uuid}/{$lessonTwo->uuid}")
      ->assertForbidden();

    $this->actingAs($learner)
      ->postJson('/api/v1/learner/progress', [
        'enrollment_id' => $enrollment->uuid,
        'lesson_id' => $lessonOne->uuid,
        'progress_percent' => 100,
        'position_seconds' => 300,
        'time_spent_delta_seconds' => 180,
      ])
      ->assertOk()
      ->assertJsonPath('data.progress.status', 'completed');

    $this->actingAs($learner)
      ->getJson("/api/v1/learner/player/{$enrollment->uuid}/{$lessonTwo->uuid}")
      ->assertOk()
      ->assertJsonPath('data.lesson.title', 'Lesson Two')
      ->assertJsonPath('data.progression.sequential', true);
  }

  public function test_instant_completion_is_rejected_without_time_spent(): void
  {
    $category = CourseCategory::query()->create([
      'name' => 'Anti Skip',
      'slug' => 'anti-skip',
      'status' => 'active',
    ]);

    $course = Course::query()->create([
      'category_id' => $category->id,
      'title' => 'Anti Skip Course',
      'slug' => 'anti-skip-course',
      'status' => CourseStatus::Published,
      'published_at' => now(),
      'is_free' => true,
    ]);

    $module = $course->modules()->create([
      'title' => 'Module',
      'slug' => 'module',
      'status' => 'published',
      'sort_order' => 1,
    ]);

    $lesson = Lesson::query()->create([
      'module_id' => $module->id,
      'course_id' => $course->id,
      'title' => 'Long Lesson',
      'slug' => 'long-lesson',
      'status' => 'published',
      'lesson_type' => LessonType::Video,
      'video_source' => 'youtube',
      'duration_minutes' => 10,
      'is_mandatory' => true,
      'completion_threshold_percent' => 100,
      'sort_order' => 1,
    ]);

    $learner = $this->memberUser();
    $this->actingAs($learner)->postJson('/api/v1/public/courses/anti-skip-course/enroll')->assertCreated();
    $enrollment = Enrollment::query()->where('user_id', $learner->id)->where('course_id', $course->id)->firstOrFail();

    $this->actingAs($learner)
      ->postJson('/api/v1/learner/progress', [
        'enrollment_id' => $enrollment->uuid,
        'lesson_id' => $lesson->uuid,
        'progress_percent' => 100,
        'position_seconds' => 0,
        'time_spent_delta_seconds' => 1,
      ])
      ->assertOk()
      ->assertJsonPath('data.progress.status', 'in_progress');
  }

  public function test_school_progress_updates_when_course_progress_changes(): void
  {
    $user = User::factory()->create();
    $school = LmsSchool::query()->create([
      'uuid' => (string) Str::uuid(),
      'slug' => 'progress-school',
      'title' => 'Progress School',
      'status' => SchoolStatus::Published,
      'member_price' => 0,
      'public_price' => 0,
      'currency' => 'USD',
      'sort_order' => 1,
      'published_at' => now(),
    ]);

    $category = CourseCategory::query()->create([
      'name' => 'School Cat',
      'slug' => 'school-cat',
      'status' => 'active',
    ]);

    $course = Course::query()->create([
      'category_id' => $category->id,
      'school_id' => $school->id,
      'title' => 'School Course',
      'slug' => 'school-progress-course',
      'status' => CourseStatus::Published,
      'published_at' => now(),
      'is_free' => true,
    ]);

    $module = $course->modules()->create([
      'title' => 'Module',
      'slug' => 'mod',
      'status' => 'published',
      'sort_order' => 1,
    ]);

    $lesson = Lesson::query()->create([
      'module_id' => $module->id,
      'course_id' => $course->id,
      'title' => 'Only Lesson',
      'slug' => 'only-lesson',
      'status' => 'published',
      'lesson_type' => LessonType::Video,
      'is_mandatory' => true,
      'completion_threshold_percent' => 100,
      'sort_order' => 1,
    ]);

    SchoolEnrollment::query()->create([
      'uuid' => (string) Str::uuid(),
      'school_id' => $school->id,
      'user_id' => $user->id,
      'status' => 'active',
      'enrolled_at' => now(),
      'progress_percent' => 0,
      'price_paid' => 0,
      'currency' => 'USD',
    ]);

    $enrollment = Enrollment::query()->create([
      'uuid' => (string) Str::uuid(),
      'course_id' => $course->id,
      'user_id' => $user->id,
      'status' => 'active',
      'enrolled_at' => now(),
      'progress_percent' => 0,
      'price_paid' => 0,
      'currency' => 'USD',
    ]);

    $this->actingAs($user)
      ->postJson('/api/v1/learner/progress', [
        'enrollment_id' => $enrollment->uuid,
        'lesson_id' => $lesson->uuid,
        'progress_percent' => 100,
        'time_spent_delta_seconds' => 300,
      ])
      ->assertOk()
      ->assertJsonPath('data.enrollment.progress_percent', 100);

    $schoolEnrollment = SchoolEnrollment::query()
      ->where('school_id', $school->id)
      ->where('user_id', $user->id)
      ->firstOrFail();

    $this->assertSame(100.0, (float) $schoolEnrollment->fresh()->progress_percent);
    $this->assertSame('completed', $schoolEnrollment->fresh()->status->value);
  }

  public function test_pending_member_gets_public_school_pricing(): void
  {
    $user = User::factory()->create();
    Member::factory()->create([
      'user_id' => $user->id,
      'approval_status' => MemberApprovalStatus::Pending,
    ]);

    $school = LmsSchool::query()->create([
      'uuid' => (string) Str::uuid(),
      'slug' => 'pricing-school',
      'title' => 'Pricing School',
      'status' => SchoolStatus::Published,
      'member_price' => 50,
      'public_price' => 75,
      'currency' => 'USD',
      'sort_order' => 1,
      'published_at' => now(),
    ]);

    $this->actingAs($user)
      ->postJson('/api/v1/public/schools/pricing-school/enroll')
      ->assertCreated()
      ->assertJsonPath('data.enrollment.price_paid', 75);
  }

  public function test_programme_modules_remain_accessible_and_keep_actual_numbers(): void
  {
    $user = User::factory()->create();
    $school = LmsSchool::query()->create([
      'uuid' => (string) Str::uuid(),
      'slug' => 'module-progression-school',
      'title' => 'Module Progression School',
      'status' => SchoolStatus::Published,
      'sequential_progression' => true,
      'member_price' => 0,
      'public_price' => 0,
      'currency' => 'USD',
      'sort_order' => 1,
      'published_at' => now(),
    ]);

    $category = CourseCategory::query()->create([
      'name' => 'Mod Prog',
      'slug' => 'mod-prog',
      'status' => 'active',
    ]);

    $moduleOne = app(ProgramModuleService::class)->createForSchool($school, [
      'title' => 'Module 1',
      'sort_order' => 1,
    ]);
    $moduleTwo = app(ProgramModuleService::class)->createForSchool($school, [
      'title' => 'Module 2',
      'sort_order' => 2,
    ]);

    $courseOne = Course::query()->create([
      'uuid' => (string) Str::uuid(),
      'category_id' => $category->id,
      'school_id' => $school->id,
      'title' => 'Course One',
      'slug' => 'course-one-mod',
      'status' => CourseStatus::Published,
      'published_at' => now(),
      'is_free' => true,
    ]);
    app(ProgramModuleService::class)->assignCourse($moduleOne, $courseOne);

    $courseTwo = Course::query()->create([
      'uuid' => (string) Str::uuid(),
      'category_id' => $category->id,
      'school_id' => $school->id,
      'title' => 'Course Two',
      'slug' => 'course-two-mod',
      'status' => CourseStatus::Published,
      'published_at' => now(),
      'is_free' => true,
    ]);
    app(ProgramModuleService::class)->assignCourse($moduleTwo, $courseTwo);

    $courseOne->modules()->create([
      'title' => 'Curriculum',
      'slug' => 'curriculum-one',
      'status' => 'published',
      'sort_order' => 1,
    ]);

    $moduleTwoLesson = Lesson::query()->create([
      'module_id' => $courseTwo->modules()->create([
        'title' => 'Curriculum Two',
        'slug' => 'curriculum-two',
        'status' => 'published',
        'sort_order' => 1,
      ])->id,
      'course_id' => $courseTwo->id,
      'title' => 'Module Two Lesson',
      'slug' => 'module-two-lesson',
      'status' => 'published',
      'lesson_type' => LessonType::Text,
      'is_mandatory' => true,
      'completion_threshold_percent' => 100,
      'sort_order' => 1,
    ]);

    SchoolEnrollment::query()->create([
      'uuid' => (string) Str::uuid(),
      'school_id' => $school->id,
      'user_id' => $user->id,
      'status' => 'active',
      'enrolled_at' => now(),
      'progress_percent' => 0,
      'price_paid' => 0,
      'currency' => 'USD',
    ]);

    Enrollment::query()->create([
      'uuid' => (string) Str::uuid(),
      'course_id' => $courseOne->id,
      'user_id' => $user->id,
      'status' => 'active',
      'enrolled_at' => now(),
      'progress_percent' => 0,
      'price_paid' => 0,
      'currency' => 'USD',
    ]);

    $enrollmentTwo = Enrollment::query()->create([
      'uuid' => (string) Str::uuid(),
      'course_id' => $courseTwo->id,
      'user_id' => $user->id,
      'status' => 'active',
      'enrolled_at' => now(),
      'progress_percent' => 0,
      'price_paid' => 0,
      'currency' => 'USD',
    ]);

    $this->actingAs($user)
      ->getJson("/api/v1/learner/player/{$enrollmentTwo->uuid}/{$moduleTwoLesson->uuid}")
      ->assertOk()
      ->assertJsonPath('data.program_module.number', 2)
      ->assertJsonPath('data.school_curriculum.modules.0.number', 1)
      ->assertJsonPath('data.school_curriculum.modules.1.number', 2)
      ->assertJsonPath('data.school_curriculum.modules.0.courses.0.status', 'not_started');
  }
}
