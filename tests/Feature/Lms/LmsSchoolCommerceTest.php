<?php

declare(strict_types=1);

namespace Tests\Feature\Lms;

use App\Modules\Communications\Models\CommunicationEmailLog;
use App\Modules\Donations\Models\PaymentProviderConfig;
use App\Modules\Lms\Enums\CourseStatus;
use App\Modules\Lms\Enums\EnrollmentStatus;
use App\Modules\Lms\Enums\SchoolStatus;
use App\Modules\Lms\Models\Course;
use App\Modules\Lms\Models\CourseCategory;
use App\Modules\Lms\Models\Enrollment;
use App\Modules\Lms\Models\LmsSchool;
use App\Modules\Lms\Models\SchoolEnrollment;
use App\Modules\Lms\Models\SchoolOrder;
use Database\Seeders\CmsSeeder;
use Database\Seeders\CommunicationSeeder;
use Database\Seeders\DonationsSeeder;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Iam\IamTestCase;

final class LmsSchoolCommerceTest extends IamTestCase
{
  protected function setUp(): void
  {
    parent::setUp();
    Storage::fake('public');
    $this->seed([CmsSeeder::class, DonationsSeeder::class, CommunicationSeeder::class]);
  }

  public function test_school_offline_payment_reject_cancels_enrollment_and_notifies(): void
  {
    Mail::fake();

    $school = LmsSchool::query()->create([
      'uuid' => (string) Str::uuid(),
      'slug' => 'reject-school',
      'title' => 'Reject School',
      'status' => SchoolStatus::Published,
      'member_price' => 50,
      'public_price' => 75,
      'currency' => 'USD',
      'published_at' => now(),
    ]);

    $learner = $this->memberUser();

    $enrollmentId = $this->actingAs($learner)
      ->postJson('/api/v1/public/schools/reject-school/enroll')
      ->assertCreated()
      ->json('data.enrollment.id');

    $checkout = $this->actingAs($learner)
      ->postJson("/api/v1/learner/school-enrollments/{$enrollmentId}/checkout", [
        'payment_method' => 'offline',
        'country' => 'nigeria',
      ])
      ->assertCreated()
      ->json('data');

    $orderId = $checkout['order']['id'];

    $this->actingAs($this->admin)
      ->postJson("/api/v1/lms/school-orders/{$orderId}/reject", [
        'reason' => 'Payment reference not found',
      ])
      ->assertOk()
      ->assertJsonPath('data.order.status', 'failed');

    $schoolEnrollment = SchoolEnrollment::query()->where('uuid', $enrollmentId)->firstOrFail();
    $this->assertSame(EnrollmentStatus::Cancelled, $schoolEnrollment->status);

    Mail::assertSent(\App\Modules\Communications\Mail\CommunicationMailable::class);

    $this->assertSame(
      1,
      CommunicationEmailLog::query()
        ->where('event_key', 'lms.payment.rejected')
        ->where('recipient_email', $learner->email)
        ->count(),
    );

    $this->actingAs($learner)
      ->postJson("/api/v1/lms/school-orders/{$orderId}/reject", [
        'reason' => 'Should not work',
      ])
      ->assertForbidden();
  }

  public function test_unauthorized_admin_cannot_reject_school_order(): void
  {
    $school = LmsSchool::query()->create([
      'uuid' => (string) Str::uuid(),
      'slug' => 'unauth-reject-school',
      'title' => 'Unauth Reject School',
      'status' => SchoolStatus::Published,
      'public_price' => 50,
      'currency' => 'USD',
      'published_at' => now(),
    ]);

    $learner = $this->memberUser();
    $enrollmentId = $this->actingAs($learner)
      ->postJson('/api/v1/public/schools/unauth-reject-school/enroll')
      ->assertCreated()
      ->json('data.enrollment.id');

    $orderId = $this->actingAs($learner)
      ->postJson("/api/v1/learner/school-enrollments/{$enrollmentId}/checkout", [
        'payment_method' => 'offline',
        'country' => 'nigeria',
      ])
      ->assertCreated()
      ->json('data.order.id');

    $this->actingAs($this->memberUser())
      ->postJson("/api/v1/lms/school-orders/{$orderId}/reject", ['reason' => 'Nope'])
      ->assertForbidden();
  }

  public function test_paid_school_enroll_checkout_offline_confirm_activates_and_syncs_courses(): void
  {
    $school = LmsSchool::query()->create([
      'uuid' => (string) Str::uuid(),
      'slug' => 'school-of-commerce',
      'title' => 'School of Commerce',
      'status' => SchoolStatus::Published,
      'member_price' => 50,
      'public_price' => 75,
      'currency' => 'USD',
      'sort_order' => 1,
      'published_at' => now(),
    ]);

    $category = CourseCategory::query()->create([
      'name' => 'School',
      'slug' => 'school',
      'status' => 'active',
    ]);

    $course = Course::query()->create([
      'uuid' => (string) Str::uuid(),
      'category_id' => $category->id,
      'school_id' => $school->id,
      'title' => 'School Course One',
      'slug' => 'school-course-one',
      'status' => CourseStatus::Published,
      'is_free' => false,
      'public_price' => 100,
      'published_at' => now(),
    ]);

    $learner = $this->memberUser();

    $enroll = $this->actingAs($learner)
      ->postJson('/api/v1/public/schools/school-of-commerce/enroll')
      ->assertCreated()
      ->assertJsonPath('data.requires_payment', true)
      ->assertJsonPath('data.enrollment.status', 'pending_payment');

    $enrollmentId = $enroll->json('data.enrollment.id');
    $schoolEnrollment = SchoolEnrollment::query()->where('uuid', $enrollmentId)->firstOrFail();
    $this->assertSame(75.0, (float) $schoolEnrollment->price_paid);

    $checkout = $this->actingAs($learner)
      ->postJson("/api/v1/learner/school-enrollments/{$enrollmentId}/checkout", [
        'payment_method' => 'offline',
        'country' => 'nigeria',
      ])
      ->assertCreated()
      ->json('data');

    $this->assertSame('instructions', $checkout['checkout']['type'] ?? null);
    $orderId = $checkout['order']['id'];
    $order = SchoolOrder::query()->where('uuid', $orderId)->firstOrFail();
    $this->assertNotNull($order->donation_id);

    $donation = $order->donation;
    $this->assertNotNull($donation);

    app(\App\Modules\Donations\Services\DonationCheckoutService::class)
      ->confirmSucceeded($donation->fresh(), $this->admin);

    $schoolEnrollment->refresh();
    $this->assertSame(EnrollmentStatus::Active, $schoolEnrollment->status);

    $courseEnrollment = Enrollment::query()
      ->where('user_id', $learner->id)
      ->where('course_id', $course->id)
      ->first();

    $this->assertNotNull($courseEnrollment);
    $this->assertSame(EnrollmentStatus::Active, $courseEnrollment->status);
    $this->assertSame(0.0, (float) $courseEnrollment->price_paid);
  }

  public function test_admin_can_list_and_confirm_school_orders(): void
  {
    $school = LmsSchool::query()->create([
      'uuid' => (string) Str::uuid(),
      'slug' => 'admin-order-school',
      'title' => 'Admin Order School',
      'status' => SchoolStatus::Published,
      'member_price' => 0,
      'public_price' => 50,
      'currency' => 'USD',
      'published_at' => now(),
    ]);

    $learner = $this->memberUser();
    $enroll = $this->actingAs($learner)
      ->postJson('/api/v1/public/schools/admin-order-school/enroll')
      ->assertCreated();

    $enrollmentId = $enroll->json('data.enrollment.id');
    $checkout = $this->actingAs($learner)
      ->postJson("/api/v1/learner/school-enrollments/{$enrollmentId}/checkout", [
        'payment_method' => 'offline',
        'country' => 'nigeria',
      ])
      ->assertCreated()
      ->json('data');

    $orderId = $checkout['order']['id'];

    Sanctum::actingAs($this->admin);

    $list = $this->getJson('/api/v1/lms/school-orders?status=awaiting_payment');
    $list->assertOk();
    $this->assertNotEmpty($list->json('data.data'));

    $this->postJson("/api/v1/lms/school-orders/{$orderId}/confirm")
      ->assertOk()
      ->assertJsonPath('data.order.enrollment_status', 'active');
  }
}
