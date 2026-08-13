<?php

declare(strict_types=1);

namespace Tests\Feature\Lms;

use App\Modules\Lms\Enums\AssessmentStatus;
use App\Modules\Lms\Enums\AssessmentType;
use App\Modules\Lms\Enums\CourseStatus;
use App\Modules\Lms\Enums\LessonType;
use App\Modules\Lms\Models\Assessment;
use App\Modules\Lms\Models\Course;
use App\Modules\Lms\Models\CourseCategory;
use App\Modules\Lms\Models\Enrollment;
use App\Modules\Lms\Models\Lesson;
use App\Models\User;
use Illuminate\Support\Str;
use Tests\Feature\Iam\IamTestCase;

/**
 * Hierarchical Course → Module → Lesson sequential curriculum.
 */
final class LmsCurriculumHierarchyTest extends IamTestCase
{
  public function test_curriculum_endpoint_groups_lessons_by_ordered_modules_with_access_states(): void
  {
    [$course, $moduleOne, $moduleTwo, $lessonOne, $lessonTwo, $lessonThree] = $this->seedSequentialCourse();

    $learner = $this->memberUser();
    $this->actingAs($learner)
      ->postJson('/api/v1/public/courses/'.$course->slug.'/enroll')
      ->assertCreated();

    $enrollment = Enrollment::query()->where('user_id', $learner->id)->where('course_id', $course->id)->firstOrFail();

    $response = $this->actingAs($learner)
      ->getJson("/api/v1/learner/enrollments/{$enrollment->uuid}/curriculum")
      ->assertOk()
      ->assertJsonPath('data.progression.sequential', true)
      ->assertJsonPath('data.modules.0.id', $moduleOne->uuid)
      ->assertJsonPath('data.modules.1.id', $moduleTwo->uuid)
      ->assertJsonPath('data.modules.0.access_state', 'available')
      ->assertJsonPath('data.modules.1.access_state', 'locked')
      ->assertJsonPath('data.modules.0.lessons.0.id', $lessonOne->uuid)
      ->assertJsonPath('data.modules.0.lessons.1.id', $lessonTwo->uuid)
      ->assertJsonPath('data.modules.1.lessons.0.id', $lessonThree->uuid)
      ->assertJsonPath('data.current_module.id', $moduleOne->uuid);

    $this->assertSame('available', $response->json('data.modules.0.lessons.0.access_state'));
    $this->assertTrue($response->json('data.modules.1.locked'));
  }

  public function test_module_two_locked_until_module_one_complete_then_unlocks(): void
  {
    [$course, $moduleOne, $moduleTwo, $lessonOne, $lessonTwo, $lessonThree] = $this->seedSequentialCourse();

    $learner = $this->memberUser();
    $this->actingAs($learner)->postJson('/api/v1/public/courses/'.$course->slug.'/enroll')->assertCreated();
    $enrollment = Enrollment::query()->where('user_id', $learner->id)->where('course_id', $course->id)->firstOrFail();

    $this->actingAs($learner)
      ->getJson("/api/v1/learner/player/{$enrollment->uuid}/{$lessonThree->uuid}")
      ->assertForbidden();

    $this->actingAs($learner)
      ->postJson('/api/v1/learner/progress', [
        'enrollment_id' => $enrollment->uuid,
        'lesson_id' => $lessonThree->uuid,
        'progress_percent' => 100,
        'time_spent_delta_seconds' => 120,
      ])
      ->assertForbidden();

    $this->completeLesson($learner, $enrollment, $lessonOne);
    $this->completeLesson($learner, $enrollment, $lessonTwo);

    $this->actingAs($learner)
      ->getJson("/api/v1/learner/enrollments/{$enrollment->uuid}/curriculum")
      ->assertOk()
      ->assertJsonPath('data.modules.0.access_state', 'completed')
      ->assertJsonPath('data.modules.1.access_state', 'available')
      ->assertJsonPath('data.current_module.id', $moduleTwo->uuid);

    $this->actingAs($learner)
      ->getJson("/api/v1/learner/player/{$enrollment->uuid}/{$lessonThree->uuid}")
      ->assertOk()
      ->assertJsonPath('data.lesson.id', $lessonThree->uuid);

    $this->completeLesson($learner, $enrollment, $lessonThree);

    $this->actingAs($learner)
      ->getJson("/api/v1/learner/enrollments/{$enrollment->uuid}/curriculum")
      ->assertOk()
      ->assertJsonPath('data.enrollment.status', 'completed')
      ->assertJsonPath('data.modules.1.access_state', 'completed');
  }

  public function test_visitor_enrollment_receives_same_module_curriculum_experience(): void
  {
    [$course] = $this->seedSequentialCourse();
    $visitor = User::factory()->create(['email' => 'visitor-curriculum@example.com']);

    $enrollment = Enrollment::query()->create([
      'uuid' => (string) Str::uuid(),
      'course_id' => $course->id,
      'user_id' => $visitor->id,
      'learner_type' => 'public',
      'status' => 'active',
      'progress_percent' => 0,
      'enrolled_at' => now(),
    ]);

    $this->actingAs($visitor)
      ->getJson("/api/v1/learner/enrollments/{$enrollment->uuid}/curriculum")
      ->assertOk()
      ->assertJsonPath('data.progression.sequential', true)
      ->assertJsonPath('data.enrollment.learner_type', 'public')
      ->assertJsonCount(2, 'data.modules');
  }

  public function test_assessment_start_rejects_locked_module_assessment(): void
  {
    [$course, $moduleOne, $moduleTwo, $lessonOne, $lessonTwo] = $this->seedSequentialCourse();

    $assessment = Assessment::query()->create([
      'uuid' => (string) Str::uuid(),
      'course_id' => $course->id,
      'module_id' => $moduleTwo->id,
      'lesson_id' => null,
      'title' => 'Module 2 Quiz',
      'slug' => 'module-2-quiz',
      'assessment_type' => AssessmentType::Quiz,
      'status' => AssessmentStatus::Published,
      'pass_mark' => 70,
      'max_attempts' => 3,
    ]);

    $learner = $this->memberUser();
    $this->actingAs($learner)->postJson('/api/v1/public/courses/'.$course->slug.'/enroll')->assertCreated();
    $enrollment = Enrollment::query()->where('user_id', $learner->id)->where('course_id', $course->id)->firstOrFail();

    $this->actingAs($learner)
      ->postJson("/api/v1/learner/assessments/{$assessment->uuid}/start", [
        'enrollment_id' => $enrollment->uuid,
      ])
      ->assertForbidden();

    $this->completeLesson($learner, $enrollment, $lessonOne);
    $this->completeLesson($learner, $enrollment, $lessonTwo);

    // Module 2 unlocked — assessment may start (no questions → still 201 with empty take payload or validation).
    $start = $this->actingAs($learner)
      ->postJson("/api/v1/learner/assessments/{$assessment->uuid}/start", [
        'enrollment_id' => $enrollment->uuid,
      ]);

    $this->assertTrue(in_array($start->status(), [201, 422], true), 'Expected start allowed or question validation, got '.$start->status());
    $this->assertNotSame(403, $start->status());
  }

  public function test_admin_student_detail_includes_module_curriculum_progress(): void
  {
    [$course, $moduleOne, $moduleTwo, $lessonOne] = $this->seedSequentialCourse();
    $learner = $this->memberUser();
    $this->actingAs($learner)->postJson('/api/v1/public/courses/'.$course->slug.'/enroll')->assertCreated();
    $enrollment = Enrollment::query()->where('user_id', $learner->id)->where('course_id', $course->id)->firstOrFail();
    $this->completeLesson($learner, $enrollment, $lessonOne);

    $admin = $this->admin;
    $this->actingAs($admin)
      ->getJson('/api/v1/lms/students/'.$learner->uuid)
      ->assertOk()
      ->assertJsonPath('data.student.enrollments.0.curriculum.modules.0.access_state', 'in_progress')
      ->assertJsonPath('data.student.enrollments.0.curriculum.modules.1.access_state', 'locked')
      ->assertJsonPath('data.student.enrollments.0.current_module.id', $moduleOne->uuid);
  }

  public function test_opt_out_sequential_progression_via_metadata(): void
  {
    $category = CourseCategory::query()->create([
      'name' => 'Open',
      'slug' => 'open-curriculum',
      'status' => 'active',
    ]);

    $course = Course::query()->create([
      'category_id' => $category->id,
      'title' => 'Open Course',
      'slug' => 'open-course',
      'status' => CourseStatus::Published,
      'published_at' => now(),
      'is_free' => true,
      'audience' => 'both',
      'metadata' => ['sequential_progression' => false],
    ]);

    $moduleTwo = $course->modules()->create([
      'title' => 'Later',
      'slug' => 'later',
      'status' => 'published',
      'sort_order' => 2,
    ]);
    $course->modules()->create([
      'title' => 'First',
      'slug' => 'first',
      'status' => 'published',
      'sort_order' => 1,
    ]);

    $lesson = Lesson::query()->create([
      'module_id' => $moduleTwo->id,
      'course_id' => $course->id,
      'title' => 'Later Lesson',
      'slug' => 'later-lesson',
      'status' => 'published',
      'lesson_type' => LessonType::Text,
      'is_mandatory' => true,
      'completion_threshold_percent' => 100,
      'sort_order' => 1,
      'content' => 'Open access',
    ]);

    $learner = $this->memberUser();
    $this->actingAs($learner)->postJson('/api/v1/public/courses/open-course/enroll')->assertCreated();
    $enrollment = Enrollment::query()->where('user_id', $learner->id)->where('course_id', $course->id)->firstOrFail();

    $this->actingAs($learner)
      ->getJson("/api/v1/learner/player/{$enrollment->uuid}/{$lesson->uuid}")
      ->assertOk();
  }

  /**
   * @return array{0: Course, 1: \App\Modules\Lms\Models\CourseModule, 2: \App\Modules\Lms\Models\CourseModule, 3: Lesson, 4: Lesson, 5: Lesson}
   */
  private function seedSequentialCourse(): array
  {
    $category = CourseCategory::query()->create([
      'name' => 'Hierarchy',
      'slug' => 'hierarchy-'.Str::random(6),
      'status' => 'active',
    ]);

    $course = Course::query()->create([
      'category_id' => $category->id,
      'title' => 'Hierarchical Course',
      'slug' => 'hierarchical-course-'.Str::random(6),
      'status' => CourseStatus::Published,
      'published_at' => now(),
      'is_free' => true,
      'audience' => 'both',
      'visitor_free' => true,
      'member_free' => true,
    ]);

    $moduleOne = $course->modules()->create([
      'title' => 'Module 1',
      'slug' => 'module-1',
      'status' => 'published',
      'sort_order' => 1,
      'description' => 'Foundations',
    ]);

    $moduleTwo = $course->modules()->create([
      'title' => 'Module 2',
      'slug' => 'module-2',
      'status' => 'published',
      'sort_order' => 2,
      'description' => 'Advanced',
    ]);

    $lessonOne = Lesson::query()->create([
      'module_id' => $moduleOne->id,
      'course_id' => $course->id,
      'title' => 'Lesson 1.1',
      'slug' => 'lesson-1-1',
      'status' => 'published',
      'lesson_type' => LessonType::Text,
      'content' => 'Intro',
      'is_mandatory' => true,
      'completion_threshold_percent' => 100,
      'sort_order' => 1,
    ]);

    $lessonTwo = Lesson::query()->create([
      'module_id' => $moduleOne->id,
      'course_id' => $course->id,
      'title' => 'Lesson 1.2',
      'slug' => 'lesson-1-2',
      'status' => 'published',
      'lesson_type' => LessonType::Text,
      'content' => 'Practice',
      'is_mandatory' => true,
      'completion_threshold_percent' => 100,
      'sort_order' => 2,
    ]);

    $lessonThree = Lesson::query()->create([
      'module_id' => $moduleTwo->id,
      'course_id' => $course->id,
      'title' => 'Lesson 2.1',
      'slug' => 'lesson-2-1',
      'status' => 'published',
      'lesson_type' => LessonType::Text,
      'content' => 'Advanced',
      'is_mandatory' => true,
      'completion_threshold_percent' => 100,
      'sort_order' => 1,
    ]);

    return [$course, $moduleOne, $moduleTwo, $lessonOne, $lessonTwo, $lessonThree];
  }

  private function completeLesson($learner, Enrollment $enrollment, Lesson $lesson): void
  {
    $this->actingAs($learner)
      ->postJson('/api/v1/learner/progress', [
        'enrollment_id' => $enrollment->uuid,
        'lesson_id' => $lesson->uuid,
        'progress_percent' => 100,
        'time_spent_delta_seconds' => 180,
      ])
      ->assertOk();
  }
}
