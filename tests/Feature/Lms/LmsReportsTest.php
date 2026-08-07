<?php

declare(strict_types=1);

namespace Tests\Feature\Lms;

use App\Modules\Lms\Enums\CourseOrderStatus;
use App\Modules\Lms\Enums\CourseStatus;
use App\Modules\Lms\Enums\EnrollmentStatus;
use App\Modules\Lms\Enums\LearnerType;
use App\Modules\Lms\Models\Course;
use App\Modules\Lms\Models\CourseCertificate;
use App\Modules\Lms\Models\CourseOrder;
use App\Modules\Lms\Models\Enrollment;
use App\Modules\Lms\Models\Instructor;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Iam\IamTestCase;

final class LmsReportsTest extends IamTestCase
{
  protected function setUp(): void
  {
    parent::setUp();
    Storage::fake('public');
  }

  public function test_dashboard_and_report_tabs_and_exports(): void
  {
    $course = Course::query()->create([
      'title' => 'Reports Course',
      'slug' => 'reports-course',
      'status' => CourseStatus::Published,
      'published_at' => now(),
      'is_free' => false,
      'public_price' => 50,
      'currency' => 'USD',
    ]);

    $instructor = Instructor::query()->create([
      'name' => 'Prof Reports',
      'slug' => 'prof-reports',
      'title' => 'Lead',
      'status' => 'active',
    ]);
    $course->instructors()->attach($instructor->id, ['is_primary' => true, 'sort_order' => 1]);

    $learner = $this->memberUser();
    $enrollment = Enrollment::query()->create([
      'course_id' => $course->id,
      'user_id' => $learner->id,
      'learner_type' => LearnerType::Public,
      'status' => EnrollmentStatus::Completed,
      'enrolled_at' => now()->subDay(),
      'completed_at' => now(),
      'progress_percent' => 100,
      'price_paid' => 50,
      'currency' => 'USD',
    ]);

    CourseOrder::query()->create([
      'order_number' => 'ORD-TEST-001',
      'enrollment_id' => $enrollment->id,
      'course_id' => $course->id,
      'user_id' => $learner->id,
      'list_amount' => 50,
      'discount_amount' => 0,
      'amount' => 50,
      'currency' => 'USD',
      'status' => CourseOrderStatus::Paid,
      'payment_method' => 'offline',
      'paid_at' => now(),
    ]);

    CourseCertificate::query()->create([
      'enrollment_id' => $enrollment->id,
      'course_id' => $course->id,
      'user_id' => $learner->id,
      'certificate_number' => 'CERT-RPT-1',
      'verification_code' => 'VERIFYRPT001',
      'status' => 'issued',
      'issued_at' => now(),
    ]);

    $this->getJson('/api/v1/lms/reports')
      ->assertOk()
      ->assertJsonPath('data.courses_total', 1)
      ->assertJsonPath('data.analytics.orders_paid', 1)
      ->assertJsonPath('data.analytics.revenue_total', 50);

    foreach (['revenue', 'students', 'instructors', 'completion', 'assessments', 'certificates', 'enrollments'] as $type) {
      $this->getJson("/api/v1/lms/reports/{$type}")
        ->assertOk()
        ->assertJsonPath('data.type', $type)
        ->assertJsonStructure(['data' => ['summary', 'columns', 'rows']]);
    }

    foreach (['csv', 'xlsx', 'pdf'] as $format) {
      $export = $this->getJson("/api/v1/lms/reports/revenue/export?format={$format}")
        ->assertOk()
        ->json('data.export');

      $this->assertNotEmpty($export['url'] ?? null);
      $this->assertNotEmpty($export['filename'] ?? null);
      Storage::disk('public')->assertExists($export['path']);
    }
  }
}
