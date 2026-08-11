<?php

declare(strict_types=1);

namespace Tests\Feature\Lms;

use App\Models\User;
use App\Modules\Lms\Enums\CourseStatus;
use App\Modules\Lms\Enums\LearnerType;
use App\Modules\Lms\Enums\SchoolStatus;
use App\Modules\Lms\Models\Course;
use App\Modules\Lms\Models\CourseCategory;
use App\Modules\Lms\Models\LmsSchool;
use App\Modules\Lms\Services\EnrollmentService;
use App\Modules\Lms\Services\SchoolEnrollmentService;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Iam\IamTestCase;

final class LmsSchoolTest extends IamTestCase
{
  public function test_public_schools_index_returns_published_only(): void
  {
    LmsSchool::query()->create([
      'uuid' => (string) Str::uuid(),
      'slug' => 'published-school',
      'title' => 'Published School',
      'status' => SchoolStatus::Published,
      'member_price' => 100,
      'public_price' => 150,
      'currency' => 'USD',
      'sort_order' => 1,
      'published_at' => now(),
    ]);
    LmsSchool::query()->create([
      'uuid' => (string) Str::uuid(),
      'slug' => 'draft-school',
      'title' => 'Draft School',
      'status' => SchoolStatus::Draft,
      'member_price' => 100,
      'public_price' => 150,
      'currency' => 'USD',
      'sort_order' => 2,
    ]);

    $response = $this->getJson('/api/v1/public/schools');

    $response->assertOk();
    $response->assertJsonPath('data.data.0.slug', 'published-school');
    $this->assertCount(1, $response->json('data.data'));
  }

  public function test_school_enrollment_gates_course_enrollment(): void
  {
    $user = User::factory()->create();
    $school = LmsSchool::query()->create([
      'uuid' => (string) Str::uuid(),
      'slug' => 'school-of-test',
      'title' => 'School of Test',
      'status' => SchoolStatus::Published,
      'member_price' => 0,
      'public_price' => 0,
      'currency' => 'USD',
      'sort_order' => 1,
      'published_at' => now(),
    ]);
    $category = CourseCategory::query()->create([
      'name' => 'Test',
      'slug' => 'test',
      'status' => 'active',
    ]);
    $course = Course::query()->create([
      'uuid' => (string) Str::uuid(),
      'category_id' => $category->id,
      'school_id' => $school->id,
      'title' => 'School Course',
      'slug' => 'school-course',
      'status' => CourseStatus::Published,
      'is_free' => true,
      'published_at' => now(),
    ]);

    $this->expectException(\App\Exceptions\BusinessException::class);
    app(EnrollmentService::class)->enroll($course, $user, LearnerType::Public);
  }

  public function test_active_school_enrollment_allows_course_enrollment(): void
  {
    $user = User::factory()->create();
    $school = LmsSchool::query()->create([
      'uuid' => (string) Str::uuid(),
      'slug' => 'school-of-access',
      'title' => 'School of Access',
      'status' => SchoolStatus::Published,
      'member_price' => 0,
      'public_price' => 0,
      'currency' => 'USD',
      'sort_order' => 1,
      'published_at' => now(),
    ]);
    $category = CourseCategory::query()->create([
      'name' => 'Access',
      'slug' => 'access',
      'status' => 'active',
    ]);
    $course = Course::query()->create([
      'uuid' => (string) Str::uuid(),
      'category_id' => $category->id,
      'school_id' => $school->id,
      'title' => 'Access Course',
      'slug' => 'access-course',
      'status' => CourseStatus::Published,
      'is_free' => true,
      'published_at' => now(),
    ]);

    app(SchoolEnrollmentService::class)->enroll($school, $user, LearnerType::Public);

    $enrollment = app(EnrollmentService::class)->enroll($course, $user, LearnerType::Public);

    $this->assertSame($course->id, $enrollment->course_id);
    $this->assertDatabaseHas('lms_school_enrollments', [
      'school_id' => $school->id,
      'user_id' => $user->id,
      'status' => 'active',
    ]);
  }

  public function test_transcript_returns_structured_payload(): void
  {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $response = $this->getJson('/api/v1/learner/transcript');

    $response->assertOk();
    $response->assertJsonStructure([
      'data' => ['schools', 'courses', 'assessments', 'assignments', 'certificates'],
    ]);
  }
}
