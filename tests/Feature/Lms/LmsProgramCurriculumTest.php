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
use App\Modules\Lms\Models\LmsProgramModule;
use App\Modules\Lms\Models\LmsSchool;
use App\Modules\Lms\Models\SchoolEnrollment;
use App\Modules\Lms\Services\ProgramModuleService;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Iam\IamTestCase;

final class LmsProgramCurriculumTest extends IamTestCase
{
  public function test_school_curriculum_returns_actual_module_numbers_not_completion_order(): void
  {
    [$school, $modules, $courses] = $this->seedSchoolWithModules();
    $learner = $this->memberUser();
    $this->enrollInSchool($learner, $school);

    foreach (['A' => 'completed', 'B' => 'completed', 'C' => 'completed', 'D' => 'completed'] as $key => $status) {
      $this->enrollCourse($learner, $courses[$key], $status === 'completed' ? 100 : 0, $status);
    }

    $experience = $this->actingAs($learner)
      ->getJson('/api/v1/learner/experience')
      ->assertOk();

    $this->assertSame('School of Teachers', $experience->json('data.learning.schools.0.school.title'));
    $this->assertSame(4, (int) $experience->json('data.learning.schools.0.courses_count'));
    $this->assertSame(4, (int) $experience->json('data.learning.schools.0.modules_count'));
    $this->assertSame(4, (int) $experience->json('data.learning.schools.0.courses_completed'));
    $this->assertSame(0, (int) $experience->json('data.learning.schools.0.courses_remaining'));

    $schoolModules = $experience->json('data.learning.schools.0.modules');
    $this->assertSame([1, 2, 4, 5], array_map(fn ($module) => (int) $module['number'], $schoolModules));
    $this->assertSame($modules[1]->uuid, $schoolModules[0]['id']);
    $this->assertSame($modules[2]->uuid, $schoolModules[1]['id']);
    $this->assertSame($modules[4]->uuid, $schoolModules[2]['id']);
    $this->assertSame($modules[5]->uuid, $schoolModules[3]['id']);

    $this->assertSame('Course A', $schoolModules[0]['courses'][0]['title']);
    $this->assertSame(1, (int) $schoolModules[0]['courses'][0]['program_module']['number']);
    $this->assertSame('Course B', $schoolModules[1]['courses'][0]['title']);
    $this->assertSame(2, (int) $schoolModules[1]['courses'][0]['program_module']['number']);
    $this->assertSame('Course C', $schoolModules[2]['courses'][0]['title']);
    $this->assertSame(4, (int) $schoolModules[2]['courses'][0]['program_module']['number']);
    $this->assertSame('Course D', $schoolModules[3]['courses'][0]['title']);
    $this->assertSame(5, (int) $schoolModules[3]['courses'][0]['program_module']['number']);

    $this->assertSame($modules[1]->uuid, $schoolModules[0]['courses'][0]['program_module']['id']);
    $this->assertSame($modules[5]->uuid, $schoolModules[3]['courses'][0]['program_module']['id']);
    $this->assertSame('completed', $schoolModules[0]['courses'][0]['status']);
    $this->assertSame('completed', $schoolModules[3]['courses'][0]['status']);
  }

  public function test_incomplete_courses_remain_visible_under_their_actual_modules(): void
  {
    [$school, $modules, $courses] = $this->seedSchoolWithModules();
    $learner = $this->memberUser();
    $this->enrollInSchool($learner, $school);
    $this->enrollCourse($learner, $courses['A'], 100, 'completed');
    $this->enrollCourse($learner, $courses['B'], 40, 'active');
    $this->enrollCourse($learner, $courses['C'], 0, 'active');
    $this->enrollCourse($learner, $courses['D'], 0, 'active');

    $payload = $this->actingAs($learner)
      ->getJson('/api/v1/learner/schools/'.$school->uuid.'/curriculum')
      ->assertOk()
      ->json('data');

    $this->assertSame(4, (int) $payload['courses_count']);
    $this->assertSame(1, (int) $payload['courses_completed']);
    $this->assertSame(1, (int) $payload['courses_in_progress']);
    $this->assertSame(2, (int) $payload['courses_remaining']);
    $this->assertSame(4, (int) $payload['modules_count']);
    $this->assertSame($modules[4]->uuid, $payload['modules'][2]['id']);
    $this->assertSame('not_started', $payload['modules'][2]['courses'][0]['status']);
    $this->assertSame('in_progress', $payload['modules'][1]['courses'][0]['status']);
    $this->assertSame('completed', $payload['modules'][0]['courses'][0]['status']);
    $this->assertSame('available', $payload['modules'][3]['courses'][0]['access_state']);
  }

  public function test_learner_can_open_an_incomplete_course_in_a_later_module(): void
  {
    [$school, , $courses] = $this->seedSchoolWithModules();
    $learner = $this->memberUser();
    $this->enrollInSchool($learner, $school);
    $this->enrollCourse($learner, $courses['A'], 0, 'active');
    $laterEnrollment = $this->enrollCourse($learner, $courses['C'], 0, 'active');
    $lesson = $courses['C']->lessons()->firstOrFail();

    $this->actingAs($learner)
      ->getJson("/api/v1/learner/player/{$laterEnrollment->uuid}/{$lesson->uuid}")
      ->assertOk()
      ->assertJsonPath('data.program_module.number', 4)
      ->assertJsonPath('data.course.title', 'Course C')
      ->assertJsonPath('data.hierarchy.program_module_number', 4)
      ->assertJsonPath('data.school_curriculum.modules.2.number', 4)
      ->assertJsonPath('data.school_curriculum.modules.2.courses.0.is_current', true)
      ->assertJsonPath('data.school_curriculum.modules.0.courses.0.status', 'not_started');
  }

  public function test_multiple_schools_are_returned_separately(): void
  {
    [$teachers, , $teacherCourses] = $this->seedSchoolWithModules('School of Teachers', 'teachers');
    [$prophets, , $prophetCourses] = $this->seedSchoolWithModules('School of Prophets', 'prophets', [1, 2]);
    $learner = $this->memberUser();
    $this->enrollInSchool($learner, $teachers);
    $this->enrollInSchool($learner, $prophets);
    $this->enrollCourse($learner, $teacherCourses['A'], 100, 'completed');
    $this->enrollCourse($learner, $prophetCourses['A'], 50, 'active');

    $schools = $this->actingAs($learner)
      ->getJson('/api/v1/learner/experience')
      ->assertOk()
      ->json('data.learning.schools');

    $this->assertCount(2, $schools);
    $titles = collect($schools)->pluck('school.title')->all();
    $this->assertContains('School of Teachers', $titles);
    $this->assertContains('School of Prophets', $titles);
  }

  public function test_free_courses_are_not_assigned_to_paid_schools(): void
  {
    [$school] = $this->seedSchoolWithModules();
    $category = CourseCategory::query()->create([
      'name' => 'Intercessory Training',
      'slug' => 'intercessory-'.Str::random(6),
      'status' => 'active',
      'is_visible' => true,
      'is_free_learning_hub' => true,
    ]);
    $freeModule = app(ProgramModuleService::class)->createForCategory($category, [
      'title' => 'Foundations',
      'sort_order' => 1,
      'status' => 'published',
    ]);
    $freeCourse = $this->makeCourse($category, 'Free Intercession', null, $freeModule, 1);

    $learner = $this->memberUser();
    $this->enrollInSchool($learner, $school);
    $this->enrollCourse($learner, $freeCourse, 20, 'active');

    $experience = $this->actingAs($learner)
      ->getJson('/api/v1/learner/experience')
      ->assertOk();

    $schoolCourseTitles = collect($experience->json('data.learning.schools.0.modules'))
      ->flatMap(fn ($module) => $module['courses'])
      ->pluck('title')
      ->all();
    $this->assertNotContains('Free Intercession', $schoolCourseTitles);
    $this->assertSame('Intercessory Training', $experience->json('data.learning.free_categories.0.category.name'));
    $this->assertSame('Free Intercession', $experience->json('data.learning.free_categories.0.modules.0.courses.0.title'));
    $this->assertNull($experience->json('data.learning.free_categories.0.modules.0.courses.0.school'));
  }

  public function test_enrollment_curriculum_includes_school_program_modules(): void
  {
    [$school, $modules, $courses] = $this->seedSchoolWithModules();
    $learner = $this->memberUser();
    $this->enrollInSchool($learner, $school);
    $enrollment = $this->enrollCourse($learner, $courses['C'], 60, 'active');

    $this->actingAs($learner)
      ->getJson("/api/v1/learner/enrollments/{$enrollment->uuid}/curriculum")
      ->assertOk()
      ->assertJsonPath('data.program_module.number', 4)
      ->assertJsonPath('data.program_module.id', $modules[4]->uuid)
      ->assertJsonPath('data.school_curriculum.school.title', $school->title)
      ->assertJsonPath('data.school_curriculum.modules.2.number', 4)
      ->assertJsonPath('data.school_curriculum.modules.2.courses.0.title', 'Course C')
      ->assertJsonPath('data.school_curriculum.modules.0.courses.0.title', 'Course A');
  }

  public function test_admin_student_profile_uses_the_same_program_module_hierarchy(): void
  {
    [$school, $modules, $courses] = $this->seedSchoolWithModules();
    $learner = $this->memberUser();
    $this->enrollInSchool($learner, $school);
    $this->enrollCourse($learner, $courses['A'], 100, 'completed');
    $this->enrollCourse($learner, $courses['C'], 25, 'active');

    $detail = $this->actingAs($this->admin)
      ->getJson('/api/v1/lms/students/'.$learner->uuid)
      ->assertOk();

    $detail
      ->assertJsonPath('data.student.schools.0.school.title', $school->title)
      ->assertJsonPath('data.student.schools.0.modules.0.number', 1)
      ->assertJsonPath('data.student.schools.0.modules.2.number', 4)
      ->assertJsonPath('data.student.schools.0.modules.2.id', $modules[4]->uuid)
      ->assertJsonPath('data.student.schools.0.modules.0.courses.0.status', 'completed')
      ->assertJsonPath('data.student.schools.0.modules.2.courses.0.status', 'in_progress');

    $enrollments = collect($detail->json('data.student.enrollments'));
    $this->assertSame(4, (int) $enrollments->firstWhere('course.title', 'Course C')['program_module']['number']);
  }

  public function test_progress_values_match_enrollment_records(): void
  {
    [$school, , $courses] = $this->seedSchoolWithModules();
    $learner = $this->memberUser();
    $this->enrollInSchool($learner, $school);
    $this->enrollCourse($learner, $courses['A'], 100, 'completed');
    $this->enrollCourse($learner, $courses['B'], 40, 'active');

    $moduleTwo = $this->actingAs($learner)
      ->getJson('/api/v1/learner/experience')
      ->assertOk()
      ->json('data.learning.schools.0.modules.1');

    $this->assertSame(40.0, (float) $moduleTwo['courses'][0]['progress_percent']);
    $this->assertSame(1, (int) $moduleTwo['courses_in_progress']);
    $this->assertSame(0, (int) $moduleTwo['courses_completed']);
  }

  public function test_visitor_cannot_read_another_learners_school_curriculum(): void
  {
    [$school] = $this->seedSchoolWithModules();
    $owner = $this->memberUser();
    $this->enrollInSchool($owner, $school);

    $visitor = User::factory()->create();
    $permission = Permission::query()->where('slug', 'learner.portal')->firstOrFail();
    $visitor->permissions()->syncWithoutDetaching([$permission->id]);
    Sanctum::actingAs($visitor);

    $this->getJson('/api/v1/learner/schools/'.$school->uuid.'/curriculum')->assertNotFound();
  }

  /**
   * @return array{0: LmsSchool, 1: array<int, LmsProgramModule>, 2: array<string, Course>}
   */
  private function seedSchoolWithModules(string $title = 'School of Teachers', string $slugPrefix = 'teachers', array $moduleNumbers = [1, 2, 4, 5]): array
  {
    $category = CourseCategory::query()->create([
      'name' => $title.' Category',
      'slug' => $slugPrefix.'-cat-'.Str::random(6),
      'status' => 'active',
    ]);

    $school = LmsSchool::query()->create([
      'uuid' => (string) Str::uuid(),
      'slug' => $slugPrefix.'-'.Str::random(6),
      'title' => $title,
      'status' => SchoolStatus::Published,
      'member_price' => 0,
      'public_price' => 0,
      'currency' => 'USD',
      'sequential_progression' => true,
      'published_at' => now(),
    ]);

    $modules = [];
    foreach ($moduleNumbers as $number) {
      $modules[$number] = app(ProgramModuleService::class)->createForSchool($school, [
        'title' => 'Module '.$number,
        'sort_order' => $number,
        'status' => 'published',
      ]);
    }

    $labels = ['A', 'B', 'C', 'D'];
    $courses = [];
    foreach ($moduleNumbers as $index => $number) {
      $label = $labels[$index] ?? 'X'.$number;
      $courses[$label] = $this->makeCourse($category, 'Course '.$label, $school, $modules[$number], $index + 1);
    }

    return [$school, $modules, $courses];
  }

  private function makeCourse(
    CourseCategory $category,
    string $title,
    ?LmsSchool $school,
    LmsProgramModule $module,
    int $sortOrder,
  ): Course {
    $course = Course::query()->create([
      'category_id' => $category->id,
      'school_id' => $school?->id,
      'program_module_id' => $module->id,
      'title' => $title,
      'slug' => Str::slug($title).'-'.Str::random(6),
      'status' => CourseStatus::Published,
      'published_at' => now(),
      'is_free' => true,
      'audience' => 'both',
      'visitor_free' => true,
      'member_free' => true,
      'sort_order' => $sortOrder,
    ]);

    $lessonModule = $course->modules()->create([
      'title' => 'Opening lesson group',
      'slug' => 'opening',
      'status' => 'published',
      'sort_order' => 1,
    ]);

    Lesson::query()->create([
      'module_id' => $lessonModule->id,
      'course_id' => $course->id,
      'title' => $title.' Lesson',
      'slug' => 'lesson-'.Str::random(6),
      'status' => 'published',
      'lesson_type' => LessonType::Text,
      'content' => 'Body',
      'is_mandatory' => true,
      'completion_threshold_percent' => 100,
      'sort_order' => 1,
    ]);

    return $course->fresh(['modules.lessons', 'programModule', 'school']);
  }

  private function enrollInSchool(User $learner, LmsSchool $school): SchoolEnrollment
  {
    return SchoolEnrollment::query()->create([
      'school_id' => $school->id,
      'user_id' => $learner->id,
      'learner_type' => LearnerType::Member,
      'status' => EnrollmentStatus::Active,
      'enrolled_at' => now(),
      'progress_percent' => 0,
    ]);
  }

  private function enrollCourse(User $learner, Course $course, float $progress, string $status): Enrollment
  {
    return Enrollment::query()->create([
      'course_id' => $course->id,
      'user_id' => $learner->id,
      'learner_type' => LearnerType::Member,
      'status' => $status === 'completed' ? EnrollmentStatus::Completed : EnrollmentStatus::Active,
      'enrolled_at' => now(),
      'completed_at' => $status === 'completed' ? now() : null,
      'progress_percent' => $progress,
    ]);
  }
}
