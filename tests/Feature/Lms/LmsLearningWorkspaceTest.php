<?php

declare(strict_types=1);

namespace Tests\Feature\Lms;

use App\Models\Permission;
use App\Models\User;
use App\Modules\Lms\Enums\CourseStatus;
use App\Modules\Lms\Enums\EnrollmentStatus;
use App\Modules\Lms\Enums\LearnerType;
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
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Iam\IamTestCase;

final class LmsLearningWorkspaceTest extends IamTestCase
{
  public function test_experience_groups_courses_under_enrolled_school(): void
  {
    [$school, $course, $moduleOne, $moduleThree, $lessonOne, $lessonThree] = $this->seedSchoolCourse();
    $learner = $this->memberUser();

    SchoolEnrollment::query()->create([
      'school_id' => $school->id,
      'user_id' => $learner->id,
      'learner_type' => LearnerType::Member,
      'status' => EnrollmentStatus::Active,
      'enrolled_at' => now(),
      'progress_percent' => 0,
    ]);

    $this->actingAs($learner)
      ->postJson('/api/v1/public/courses/'.$course->slug.'/enroll')
      ->assertCreated();

    $enrollment = Enrollment::query()->where('user_id', $learner->id)->where('course_id', $course->id)->firstOrFail();
    $this->completeLesson($learner, $enrollment, $lessonThree);

    $experience = $this->actingAs($learner)
      ->getJson('/api/v1/learner/experience')
      ->assertOk()
      ->assertJsonPath('data.learning.summary.schools_enrolled', 1)
      ->assertJsonPath('data.learning.summary.courses_enrolled', 1)
      ->assertJsonPath('data.learning.schools.0.school.title', 'School of Teachers')
      ->assertJsonPath('data.learning.schools.0.modules.0.number', 1)
      ->assertJsonPath('data.learning.schools.0.modules.0.courses.0.title', 'Foundations of Teaching Ministry')
      ->assertJsonPath('data.continue_learning.0.school.title', 'School of Teachers');

    $this->assertGreaterThan(0, (int) $experience->json('data.learning.schools.0.courses_count'));
  }

  public function test_player_exposes_school_course_module_hierarchy_and_other_modules(): void
  {
    [$school, $course, $moduleOne, $moduleThree, $lessonOne, $lessonThree] = $this->seedSchoolCourse();
    $learner = $this->memberUser();
    $enrollment = $this->enrollInSchoolCourse($learner, $school, $course);

    $this->actingAs($learner)
      ->getJson("/api/v1/learner/player/{$enrollment->uuid}/{$lessonThree->uuid}")
      ->assertOk()
      ->assertJsonPath('data.school.title', $school->title)
      ->assertJsonPath('data.course.title', $course->title)
      ->assertJsonPath('data.current_module.id', $moduleThree->uuid)
      ->assertJsonPath('data.hierarchy.school_title', $school->title)
      ->assertJsonPath('data.hierarchy.course_title', $course->title)
      ->assertJsonPath('data.hierarchy.course_module_title', $moduleThree->title)
      ->assertJsonPath('data.hierarchy.lesson_title', $lessonThree->title)
      ->assertJsonPath('data.curriculum.0.id', $moduleOne->uuid)
      ->assertJsonPath('data.curriculum.2.id', $moduleThree->uuid)
      ->assertJsonPath('data.curriculum.0.locked', false)
      ->assertJsonPath('data.curriculum.2.locked', false);

    unset($lessonOne);
  }

  public function test_pending_payment_enrollment_cannot_open_protected_lesson(): void
  {
    [, $course, , , , $lessonThree] = $this->seedSchoolCourse();
    $learner = $this->memberUser();

    $enrollment = Enrollment::query()->create([
      'course_id' => $course->id,
      'user_id' => $learner->id,
      'learner_type' => LearnerType::Member,
      'status' => EnrollmentStatus::PendingPayment,
      'enrolled_at' => now(),
    ]);

    $this->actingAs($learner)
      ->getJson("/api/v1/learner/player/{$enrollment->uuid}/{$lessonThree->uuid}")
      ->assertForbidden();
  }

  public function test_visitor_cannot_access_another_learners_curriculum(): void
  {
    [$school, $course, , , , $lessonThree] = $this->seedSchoolCourse();
    $owner = $this->memberUser();
    $enrollment = $this->enrollInSchoolCourse($owner, $school, $course);

    $visitor = User::factory()->create();
    $permission = Permission::query()->where('slug', 'learner.portal')->firstOrFail();
    $visitor->permissions()->syncWithoutDetaching([$permission->id]);
    Sanctum::actingAs($visitor);

    $this->getJson("/api/v1/learner/player/{$enrollment->uuid}/{$lessonThree->uuid}")->assertNotFound();
    $this->getJson("/api/v1/learner/enrollments/{$enrollment->uuid}/curriculum")->assertNotFound();
  }

  public function test_admin_student_profile_groups_progress_by_school(): void
  {
    [$school, $course, $moduleOne, , , $lessonThree] = $this->seedSchoolCourse();
    $learner = $this->memberUser();
    $enrollment = $this->enrollInSchoolCourse($learner, $school, $course);
    $this->completeLesson($learner, $enrollment, $lessonThree);

    $this->actingAs($this->admin)
      ->getJson('/api/v1/lms/students/'.$learner->uuid)
      ->assertOk()
      ->assertJsonPath('data.student.schools.0.school.title', $school->title)
      ->assertJsonPath('data.student.schools.0.modules.0.courses.0.title', $course->title)
      ->assertJsonPath('data.student.enrollments.0.curriculum.modules.0.id', $moduleOne->uuid)
      ->assertJsonPath('data.student.enrollments.0.curriculum.modules.2.access_state', 'completed');
  }

  /**
   * @return array{0: LmsSchool, 1: Course, 2: \App\Modules\Lms\Models\CourseModule, 3: \App\Modules\Lms\Models\CourseModule, 4: Lesson, 5: Lesson}
   */
  private function seedSchoolCourse(): array
  {
    $category = CourseCategory::query()->create([
      'name' => 'Teaching',
      'slug' => 'teaching-'.Str::random(6),
      'status' => 'active',
    ]);

    $school = LmsSchool::query()->create([
      'uuid' => (string) Str::uuid(),
      'slug' => 'school-of-teachers-'.Str::random(6),
      'title' => 'School of Teachers',
      'status' => SchoolStatus::Published,
      'member_price' => 0,
      'public_price' => 0,
      'currency' => 'USD',
      'sequential_progression' => false,
      'published_at' => now(),
    ]);

    $programModule = app(ProgramModuleService::class)->createForSchool($school, [
      'title' => 'Module 1',
      'sort_order' => 1,
      'status' => 'published',
    ]);

    $course = Course::query()->create([
      'category_id' => $category->id,
      'school_id' => $school->id,
      'program_module_id' => $programModule->id,
      'title' => 'Foundations of Teaching Ministry',
      'slug' => 'foundations-teaching-'.Str::random(6),
      'status' => CourseStatus::Published,
      'published_at' => now(),
      'is_free' => true,
      'audience' => 'both',
      'visitor_free' => true,
      'member_free' => true,
    ]);

    $moduleOne = $course->modules()->create([
      'title' => 'Foundations of Biblical Teaching',
      'slug' => 'module-1',
      'status' => 'published',
      'sort_order' => 1,
    ]);
    $moduleTwo = $course->modules()->create([
      'title' => 'Effective Teaching Methods',
      'slug' => 'module-2',
      'status' => 'published',
      'sort_order' => 2,
    ]);
    $moduleThree = $course->modules()->create([
      'title' => 'Teaching the Word',
      'slug' => 'module-3',
      'status' => 'published',
      'sort_order' => 3,
    ]);

    $lessonOne = Lesson::query()->create([
      'module_id' => $moduleOne->id,
      'course_id' => $course->id,
      'title' => 'Lesson 1',
      'slug' => 'lesson-1',
      'status' => 'published',
      'lesson_type' => LessonType::Text,
      'content' => 'One',
      'is_mandatory' => true,
      'completion_threshold_percent' => 100,
      'sort_order' => 1,
    ]);
    Lesson::query()->create([
      'module_id' => $moduleTwo->id,
      'course_id' => $course->id,
      'title' => 'Lesson 2',
      'slug' => 'lesson-2',
      'status' => 'published',
      'lesson_type' => LessonType::Text,
      'content' => 'Two',
      'is_mandatory' => true,
      'completion_threshold_percent' => 100,
      'sort_order' => 1,
    ]);
    $lessonThree = Lesson::query()->create([
      'module_id' => $moduleThree->id,
      'course_id' => $course->id,
      'title' => 'Lesson 3',
      'slug' => 'lesson-3',
      'status' => 'published',
      'lesson_type' => LessonType::Text,
      'content' => 'Three',
      'is_mandatory' => true,
      'completion_threshold_percent' => 100,
      'sort_order' => 1,
    ]);

    return [$school, $course, $moduleOne, $moduleThree, $lessonOne, $lessonThree];
  }

  private function enrollInSchoolCourse(User $learner, LmsSchool $school, Course $course): Enrollment
  {
    SchoolEnrollment::query()->create([
      'school_id' => $school->id,
      'user_id' => $learner->id,
      'learner_type' => LearnerType::Member,
      'status' => EnrollmentStatus::Active,
      'enrolled_at' => now(),
      'progress_percent' => 0,
    ]);

    $this->actingAs($learner)
      ->postJson('/api/v1/public/courses/'.$course->slug.'/enroll')
      ->assertCreated();

    return Enrollment::query()->where('user_id', $learner->id)->where('course_id', $course->id)->firstOrFail();
  }

  private function completeLesson(User $learner, Enrollment $enrollment, Lesson $lesson): void
  {
    $this->actingAs($learner)
      ->postJson('/api/v1/learner/progress', [
        'enrollment_id' => $enrollment->uuid,
        'lesson_id' => $lesson->uuid,
        'progress_percent' => 100,
        'time_spent_delta_seconds' => 120,
      ])
      ->assertOk();
  }
}
