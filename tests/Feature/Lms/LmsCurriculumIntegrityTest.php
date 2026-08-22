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

final class LmsCurriculumIntegrityTest extends IamTestCase
{
  public function test_admin_integrity_detects_duplicate_and_missing_sort_order_without_repairing(): void
  {
    $school = $this->makeSchool();
    $service = app(ProgramModuleService::class);
    $moduleFourA = $service->createForSchool($school, ['title' => 'Module 4', 'sort_order' => 4, 'status' => 'published']);
    $moduleFive = $service->createForSchool($school, ['title' => 'Module 5', 'sort_order' => 4, 'status' => 'published']);
    $service->createForSchool($school, ['title' => 'Module 6', 'sort_order' => 6, 'status' => 'published']);

    $this->makeCourse($school, $moduleFourA, 'Presence');
    $this->makeCourse($school, $moduleFive, 'Witness of Fire');

    $beforeFour = $moduleFourA->fresh()->sort_order;
    $beforeFive = $moduleFive->fresh()->sort_order;

    $response = $this->getJson('/api/v1/lms/schools/'.$school->uuid.'/curriculum-integrity')
      ->assertOk();

    $codes = collect($response->json('data.curriculum_integrity.issues'))->pluck('code')->all();
    $this->assertContains('duplicate_sort_order', $codes);
    $this->assertContains('missing_sort_order', $codes);
    $this->assertFalse((bool) $response->json('data.curriculum_integrity.auto_repaired'));
    $this->assertSame(4, (int) $moduleFourA->fresh()->sort_order);
    $this->assertSame(4, (int) $moduleFive->fresh()->sort_order);
    $this->assertSame($beforeFour, $moduleFourA->fresh()->sort_order);
    $this->assertSame($beforeFive, $moduleFive->fresh()->sort_order);
  }

  public function test_admin_integrity_detects_empty_modules_and_unassigned_courses(): void
  {
    $school = $this->makeSchool();
    $service = app(ProgramModuleService::class);
    $filled = $service->createForSchool($school, ['title' => 'Module 1', 'sort_order' => 1, 'status' => 'published']);
    $empty = $service->createForSchool($school, ['title' => 'Module 2', 'sort_order' => 2, 'status' => 'published']);
    $this->makeCourse($school, $filled, 'Assigned Course');
    $this->makeCourse($school, null, 'Unassigned Course');

    $response = $this->getJson('/api/v1/lms/schools/'.$school->uuid.'/curriculum-integrity')
      ->assertOk();

    $codes = collect($response->json('data.curriculum_integrity.issues'))->pluck('code')->all();
    $this->assertContains('empty_programme_module', $codes);
    $this->assertContains('course_without_program_module', $codes);
    $this->assertSame(0, $empty->fresh()->courses()->count());
  }

  public function test_school_show_includes_integrity_and_programme_module_numbers(): void
  {
    $school = $this->makeSchool();
    $module = app(ProgramModuleService::class)->createForSchool($school, [
      'title' => 'Module 7',
      'sort_order' => 7,
      'status' => 'published',
    ]);
    $this->makeCourse($school, $module, 'Elements of a Sermon');

    $response = $this->getJson('/api/v1/lms/schools/'.$school->uuid)->assertOk();
    $this->assertSame(7, (int) $response->json('data.school.program_modules.0.number'));
    $this->assertSame(7, (int) $response->json('data.school.program_modules.0.sort_order'));
    $this->assertArrayHasKey('curriculum_integrity', $response->json('data'));
  }

  public function test_public_school_exposes_programme_module_number(): void
  {
    $school = $this->makeSchool();
    $module = app(ProgramModuleService::class)->createForSchool($school, [
      'title' => 'Module 3',
      'sort_order' => 3,
      'status' => 'published',
    ]);
    $this->makeCourse($school, $module, 'Grace And Truth');

    $this->app['auth']->forgetGuards();
    $this->flushSession();

    $response = $this->getJson('/api/v1/public/schools/'.$school->slug)->assertOk();
    $this->assertSame(3, (int) $response->json('data.school.program_modules.0.number'));
    $this->assertSame('Module 3', $response->json('data.school.program_modules.0.title'));
    $this->assertNotNull($response->json('data.school.program_modules.0.courses.0.program_module'));
    $this->assertSame(3, (int) $response->json('data.school.program_modules.0.courses.0.program_module.number'));
  }

  private function makeSchool(): LmsSchool
  {
    return LmsSchool::query()->create([
      'uuid' => (string) Str::uuid(),
      'slug' => 'teachers-'.Str::random(6),
      'title' => 'School of Teachers',
      'status' => SchoolStatus::Published,
      'member_price' => 0,
      'public_price' => 0,
      'currency' => 'USD',
      'published_at' => now(),
    ]);
  }

  private function makeCourse(LmsSchool $school, ?LmsProgramModule $module, string $title): Course
  {
    $category = CourseCategory::query()->first() ?? CourseCategory::query()->create([
      'name' => 'General',
      'slug' => 'general-'.Str::random(6),
      'status' => 'active',
    ]);

    return Course::query()->create([
      'category_id' => $category->id,
      'school_id' => $school->id,
      'program_module_id' => $module?->id,
      'title' => $title,
      'slug' => Str::slug($title).'-'.Str::random(6),
      'status' => CourseStatus::Published,
      'published_at' => now(),
      'audience' => 'both',
      'sort_order' => 0,
    ]);
  }
}
