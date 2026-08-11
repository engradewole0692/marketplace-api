<?php

declare(strict_types=1);

namespace Tests\Feature\Lms;

use App\Modules\Lms\Enums\CourseStatus;
use App\Modules\Lms\Enums\SchoolStatus;
use App\Modules\Lms\Models\Course;
use App\Modules\Lms\Models\CourseCategory;
use App\Modules\Lms\Models\LmsProgramModule;
use App\Modules\Lms\Models\LmsSchool;
use App\Modules\Lms\Services\ProgramModuleService;
use Illuminate\Support\Str;
use Tests\Feature\Iam\IamTestCase;

final class LmsProgramModuleTest extends IamTestCase
{
  public function test_admin_can_create_assign_and_reorder_school_program_modules(): void
  {
    $school = LmsSchool::query()->create([
      'uuid' => (string) Str::uuid(),
      'slug' => 'school-of-pastors',
      'title' => 'School of Pastors',
      'status' => SchoolStatus::Published,
      'member_price' => 100,
      'public_price' => 150,
      'currency' => 'USD',
      'sort_order' => 1,
      'published_at' => now(),
    ]);

    $category = CourseCategory::query()->create([
      'name' => 'Pastoral',
      'slug' => 'pastoral',
      'status' => 'active',
    ]);

    $courseA = Course::query()->create([
      'uuid' => (string) Str::uuid(),
      'category_id' => $category->id,
      'school_id' => $school->id,
      'title' => 'Course A',
      'slug' => 'course-a',
      'status' => CourseStatus::Published,
      'is_free' => true,
      'published_at' => now(),
    ]);

    $courseB = Course::query()->create([
      'uuid' => (string) Str::uuid(),
      'category_id' => $category->id,
      'school_id' => $school->id,
      'title' => 'Course B',
      'slug' => 'course-b',
      'status' => CourseStatus::Published,
      'is_free' => true,
      'published_at' => now(),
    ]);

    $createModule = $this->postJson("/api/v1/lms/schools/{$school->uuid}/program-modules", [
      'title' => 'Module 1 — Foundation',
    ]);

    $createModule->assertCreated();
    $moduleId = $createModule->json('data.program_module.id');
    $this->assertNotEmpty($moduleId);

    $assignA = $this->postJson("/api/v1/lms/program-modules/{$moduleId}/assign-course", [
      'course_id' => $courseA->uuid,
    ]);
    $assignA->assertOk();

    $assignB = $this->postJson("/api/v1/lms/program-modules/{$moduleId}/assign-course", [
      'course_id' => $courseB->uuid,
    ]);
    $assignB->assertOk();

    $reorder = $this->postJson("/api/v1/lms/program-modules/{$moduleId}/courses/reorder", [
      'course_ids' => [$courseB->uuid, $courseA->uuid],
    ]);
    $reorder->assertOk();

    $courseB->refresh();
    $courseA->refresh();
    $this->assertSame(1, (int) $courseB->sort_order);
    $this->assertSame(2, (int) $courseA->sort_order);

    $public = $this->getJson('/api/v1/public/schools/school-of-pastors');
    $public->assertOk();
    $public->assertJsonPath('data.school.program_modules.0.title', 'Module 1 — Foundation');
    $public->assertJsonPath('data.school.program_modules.0.courses.0.slug', 'course-b');
  }

  public function test_public_free_category_returns_program_module_hierarchy(): void
  {
    $category = CourseCategory::query()->create([
      'uuid' => (string) Str::uuid(),
      'name' => 'Intercessory Training',
      'slug' => 'intercessory-training',
      'status' => 'active',
      'is_visible' => true,
      'is_free_learning_hub' => true,
    ]);

    $module = app(ProgramModuleService::class)->createForCategory($category, [
      'title' => 'Module 1',
      'status' => 'published',
    ]);

    $course = Course::query()->create([
      'uuid' => (string) Str::uuid(),
      'category_id' => $category->id,
      'title' => 'Foundations of Intercession',
      'slug' => 'foundations-of-intercession',
      'status' => CourseStatus::Published,
      'is_free' => true,
      'published_at' => now(),
    ]);

    app(ProgramModuleService::class)->assignCourse($module, $course);

    $index = $this->getJson('/api/v1/public/free-categories');
    $index->assertOk();
    $index->assertJsonPath('data.data.0.slug', 'intercessory-training');

    $show = $this->getJson('/api/v1/public/free-categories/intercessory-training');
    $show->assertOk();
    $show->assertJsonPath('data.category.program_modules.0.courses.0.slug', 'foundations-of-intercession');
  }

  public function test_course_update_accepts_program_module_id(): void
  {
    $school = LmsSchool::query()->create([
      'uuid' => (string) Str::uuid(),
      'slug' => 'school-of-teachers',
      'title' => 'School of Teachers',
      'status' => SchoolStatus::Draft,
      'member_price' => 0,
      'public_price' => 0,
      'currency' => 'USD',
      'sort_order' => 1,
    ]);

    $module = app(ProgramModuleService::class)->createForSchool($school, [
      'title' => 'Module 1',
    ]);

    $category = CourseCategory::query()->create([
      'name' => 'Teaching',
      'slug' => 'teaching',
      'status' => 'active',
    ]);

    $course = Course::query()->create([
      'uuid' => (string) Str::uuid(),
      'category_id' => $category->id,
      'school_id' => $school->id,
      'title' => 'Teaching Basics',
      'slug' => 'teaching-basics',
      'status' => CourseStatus::Draft,
      'is_free' => true,
    ]);

    $response = $this->putJson("/api/v1/lms/courses/{$course->uuid}", [
      'program_module_id' => $module->uuid,
    ]);

    $response->assertOk();
    $course->refresh();
    $this->assertSame($module->id, $course->program_module_id);
  }
}
