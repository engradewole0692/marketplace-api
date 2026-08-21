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
 * Hierarchical Course → Module → Lesson curriculum with open module access by default.
 */
final class LmsCurriculumHierarchyTest extends IamTestCase
{
  public function test_enrolled_learner_sees_all_modules_available_in_order(): void
  {
    [$course, $moduleOne, $moduleTwo, $moduleThree, $lessonOne, $lessonTwo, $lessonThree] = $this->seedOpenCourse();

    $learner = $this->memberUser();
    $this->actingAs($learner)
      ->postJson('/api/v1/public/courses/'.$course->slug.'/enroll')
      ->assertCreated();

    $enrollment = Enrollment::query()->where('user_id', $learner->id)->where('course_id', $course->id)->firstOrFail();

    $response = $this->actingAs($learner)
      ->getJson("/api/v1/learner/enrollments/{$enrollment->uuid}/curriculum")
      ->assertOk()
      ->assertJsonPath('data.progression.sequential', false)
      ->assertJsonPath('data.modules.0.id', $moduleOne->uuid)
      ->assertJsonPath('data.modules.1.id', $moduleTwo->uuid)
      ->assertJsonPath('data.modules.2.id', $moduleThree->uuid)
      ->assertJsonPath('data.modules.0.access_state', 'available')
      ->assertJsonPath('data.modules.1.access_state', 'available')
      ->assertJsonPath('data.modules.2.access_state', 'available')
      ->assertJsonPath('data.modules.0.locked', false)
      ->assertJsonPath('data.modules.1.locked', false)
      ->assertJsonPath('data.modules.2.locked', false)
      ->assertJsonPath('data.modules.0.lessons.0.id', $lessonOne->uuid)
      ->assertJsonPath('data.modules.1.lessons.0.id', $lessonTwo->uuid)
      ->assertJsonPath('data.modules.2.lessons.0.id', $lessonThree->uuid);

    $this->assertSame('available', $response->json('data.modules.0.lessons.0.access_state'));
    $this->assertFalse($response->json('data.modules.1.locked'));
  }

  public function test_module_two_and_three_accessible_when_prior_modules_incomplete(): void
  {
    [$course, $moduleOne, $moduleTwo, $moduleThree, $lessonOne, $lessonTwo, $lessonThree] = $this->seedOpenCourse();

    $learner = $this->memberUser();
    $this->actingAs($learner)->postJson('/api/v1/public/courses/'.$course->slug.'/enroll')->assertCreated();
    $enrollment = Enrollment::query()->where('user_id', $learner->id)->where('course_id', $course->id)->firstOrFail();

    $this->actingAs($learner)
      ->getJson("/api/v1/learner/player/{$enrollment->uuid}/{$lessonTwo->uuid}")
      ->assertOk()
      ->assertJsonPath('data.lesson.id', $lessonTwo->uuid);

    $this->actingAs($learner)
      ->getJson("/api/v1/learner/player/{$enrollment->uuid}/{$lessonThree->uuid}")
      ->assertOk()
      ->assertJsonPath('data.lesson.id', $lessonThree->uuid);

    $this->completeLesson($learner, $enrollment, $lessonThree);

    $this->actingAs($learner)
      ->getJson("/api/v1/learner/enrollments/{$enrollment->uuid}/curriculum")
      ->assertOk()
      ->assertJsonPath('data.modules.0.access_state', 'available')
      ->assertJsonPath('data.modules.1.access_state', 'available')
      ->assertJsonPath('data.modules.2.access_state', 'completed');

    // Module 1 still incomplete — course not completed.
    $status = $enrollment->fresh()->status;
    $statusValue = $status instanceof \BackedEnum ? $status->value : (string) $status;
    $this->assertNotSame('completed', $statusValue);
  }

  public function test_progress_recorded_independently_per_module(): void
  {
    [$course, , , , $lessonOne, $lessonTwo, $lessonThree] = $this->seedOpenCourse();

    $learner = $this->memberUser();
    $this->actingAs($learner)->postJson('/api/v1/public/courses/'.$course->slug.'/enroll')->assertCreated();
    $enrollment = Enrollment::query()->where('user_id', $learner->id)->where('course_id', $course->id)->firstOrFail();

    $this->completeLesson($learner, $enrollment, $lessonThree);
    $this->completeLesson($learner, $enrollment, $lessonTwo);

    $curriculum = $this->actingAs($learner)
      ->getJson("/api/v1/learner/enrollments/{$enrollment->uuid}/curriculum")
      ->assertOk();

    $this->assertSame('available', $curriculum->json('data.modules.0.access_state'));
    $this->assertSame('completed', $curriculum->json('data.modules.1.access_state'));
    $this->assertSame('completed', $curriculum->json('data.modules.2.access_state'));
    $this->assertTrue($curriculum->json('data.modules.0.lessons.0.access_state') === 'available');
    $this->assertGreaterThan(0, (float) $curriculum->json('data.enrollment.progress_percent'));
  }

  public function test_assessment_accessible_on_later_module_without_prior_completion(): void
  {
    [$course, , $moduleTwo, , $lessonOne] = $this->seedOpenCourse();

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

    $start = $this->actingAs($learner)
      ->postJson("/api/v1/learner/assessments/{$assessment->uuid}/start", [
        'enrollment_id' => $enrollment->uuid,
      ]);

    $this->assertTrue(in_array($start->status(), [201, 422], true), 'Expected start allowed or question validation, got '.$start->status());
    $this->assertNotSame(403, $start->status());
    unset($lessonOne);
  }

  public function test_visitor_enrollment_receives_same_open_module_curriculum(): void
  {
    [$course] = $this->seedOpenCourse();
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
      ->assertJsonPath('data.progression.sequential', false)
      ->assertJsonPath('data.enrollment.learner_type', 'public')
      ->assertJsonCount(3, 'data.modules')
      ->assertJsonPath('data.modules.1.access_state', 'available')
      ->assertJsonPath('data.modules.2.access_state', 'available');
  }

  public function test_admin_student_detail_includes_ordered_module_progress(): void
  {
    [$course, $moduleOne, , , $lessonOne, , $lessonThree] = $this->seedOpenCourse();
    $learner = $this->memberUser();
    $this->actingAs($learner)->postJson('/api/v1/public/courses/'.$course->slug.'/enroll')->assertCreated();
    $enrollment = Enrollment::query()->where('user_id', $learner->id)->where('course_id', $course->id)->firstOrFail();
    $this->completeLesson($learner, $enrollment, $lessonThree);
    $this->completeLesson($learner, $enrollment, $lessonOne);

    $admin = $this->admin;
    $detail = $this->actingAs($admin)
      ->getJson('/api/v1/lms/students/'.$learner->uuid)
      ->assertOk()
      ->assertJsonPath('data.student.enrollments.0.curriculum.modules.0.access_state', 'completed')
      ->assertJsonPath('data.student.enrollments.0.curriculum.modules.1.access_state', 'available')
      ->assertJsonPath('data.student.enrollments.0.curriculum.modules.2.access_state', 'completed');

    $this->assertNotNull($detail->json('data.student.enrollments.0.current_module.id'));
    unset($moduleOne);
  }

  public function test_non_enrolled_learner_cannot_access_player(): void
  {
    [, , , , , , $lessonThree] = $this->seedOpenCourse();
    $outsider = $this->memberUser();

    $this->actingAs($outsider)
      ->getJson('/api/v1/learner/player/'.(string) Str::uuid().'/'.$lessonThree->uuid)
      ->assertNotFound();
  }

  public function test_opt_in_sequential_progression_via_metadata_still_locks(): void
  {
    [$course, , , , , , $lessonThree] = $this->seedOpenCourse([
      'sequential_progression' => true,
    ]);

    $learner = $this->memberUser();
    $this->actingAs($learner)->postJson('/api/v1/public/courses/'.$course->slug.'/enroll')->assertCreated();
    $enrollment = Enrollment::query()->where('user_id', $learner->id)->where('course_id', $course->id)->firstOrFail();

    $this->actingAs($learner)
      ->getJson("/api/v1/learner/player/{$enrollment->uuid}/{$lessonThree->uuid}")
      ->assertForbidden();

    $this->actingAs($learner)
      ->getJson("/api/v1/learner/enrollments/{$enrollment->uuid}/curriculum")
      ->assertOk()
      ->assertJsonPath('data.progression.sequential', true)
      ->assertJsonPath('data.modules.2.access_state', 'locked');
  }

  public function test_course_completes_only_when_all_modules_done(): void
  {
    [$course, , , , $lessonOne, $lessonTwo, $lessonThree] = $this->seedOpenCourse();

    $learner = $this->memberUser();
    $this->actingAs($learner)->postJson('/api/v1/public/courses/'.$course->slug.'/enroll')->assertCreated();
    $enrollment = Enrollment::query()->where('user_id', $learner->id)->where('course_id', $course->id)->firstOrFail();

    $this->completeLesson($learner, $enrollment, $lessonThree);
    $status = $enrollment->fresh()->status;
    $statusValue = $status instanceof \BackedEnum ? $status->value : (string) $status;
    $this->assertNotSame('completed', $statusValue);

    $this->completeLesson($learner, $enrollment, $lessonOne);
    $this->completeLesson($learner, $enrollment, $lessonTwo);

    $this->actingAs($learner)
      ->getJson("/api/v1/learner/enrollments/{$enrollment->uuid}/curriculum")
      ->assertOk()
      ->assertJsonPath('data.enrollment.status', 'completed')
      ->assertJsonPath('data.modules.0.access_state', 'completed')
      ->assertJsonPath('data.modules.1.access_state', 'completed')
      ->assertJsonPath('data.modules.2.access_state', 'completed');
  }

  /**
   * @param  array<string, mixed>  $metadata
   * @return array{0: Course, 1: \App\Modules\Lms\Models\CourseModule, 2: \App\Modules\Lms\Models\CourseModule, 3: \App\Modules\Lms\Models\CourseModule, 4: Lesson, 5: Lesson, 6: Lesson}
   */
  private function seedOpenCourse(array $metadata = []): array
  {
    $category = CourseCategory::query()->create([
      'name' => 'Hierarchy',
      'slug' => 'hierarchy-'.Str::random(6),
      'status' => 'active',
    ]);

    $course = Course::query()->create([
      'category_id' => $category->id,
      'title' => 'Open Hierarchy Course',
      'slug' => 'open-hierarchy-'.Str::random(6),
      'status' => CourseStatus::Published,
      'published_at' => now(),
      'is_free' => true,
      'audience' => 'both',
      'visitor_free' => true,
      'member_free' => true,
      'certificate_enabled' => false,
      'metadata' => $metadata === [] ? null : $metadata,
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
      'description' => 'Practice',
    ]);

    $moduleThree = $course->modules()->create([
      'title' => 'Module 3',
      'slug' => 'module-3',
      'status' => 'published',
      'sort_order' => 3,
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
      'module_id' => $moduleTwo->id,
      'course_id' => $course->id,
      'title' => 'Lesson 2.1',
      'slug' => 'lesson-2-1',
      'status' => 'published',
      'lesson_type' => LessonType::Text,
      'content' => 'Practice',
      'is_mandatory' => true,
      'completion_threshold_percent' => 100,
      'sort_order' => 1,
    ]);

    $lessonThree = Lesson::query()->create([
      'module_id' => $moduleThree->id,
      'course_id' => $course->id,
      'title' => 'Lesson 3.1',
      'slug' => 'lesson-3-1',
      'status' => 'published',
      'lesson_type' => LessonType::Text,
      'content' => 'Advanced',
      'is_mandatory' => true,
      'completion_threshold_percent' => 100,
      'sort_order' => 1,
    ]);

    return [$course, $moduleOne, $moduleTwo, $moduleThree, $lessonOne, $lessonTwo, $lessonThree];
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
