<?php

declare(strict_types=1);

namespace Tests\Feature\Lms;

use App\Modules\Lms\Enums\CourseOrderStatus;
use App\Modules\Lms\Enums\CourseStatus;
use App\Modules\Lms\Enums\EnrollmentStatus;
use App\Modules\Lms\Models\Course;
use App\Modules\Lms\Models\CourseCoupon;
use App\Modules\Lms\Models\CourseOrder;
use App\Modules\Lms\Models\Enrollment;
use Database\Seeders\CmsSeeder;
use Database\Seeders\DonationsSeeder;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Iam\IamTestCase;

final class LmsCourseCommerceTest extends IamTestCase
{
  protected function setUp(): void
  {
    parent::setUp();
    Storage::fake('public');
    $this->seed([CmsSeeder::class, DonationsSeeder::class]);
  }

  public function test_paid_enroll_checkout_offline_confirm_activates_and_refunds(): void
  {
    $course = Course::query()->create([
      'title' => 'Paid Leadership',
      'slug' => 'paid-leadership',
      'status' => CourseStatus::Published,
      'published_at' => now(),
      'is_free' => false,
      'member_price' => 25,
      'public_price' => 40,
      'currency' => 'USD',
    ]);

    CourseCoupon::query()->create([
      'code' => 'SAVE5',
      'name' => 'Save 5',
      'discount_type' => 'fixed',
      'discount_value' => 5,
      'applies_to' => 'all',
      'status' => 'active',
    ]);

    $learner = $this->memberUser();

    $enroll = $this->actingAs($learner)
      ->postJson('/api/v1/public/courses/paid-leadership/enroll', [
        'coupon_code' => 'SAVE5',
      ])
      ->assertCreated()
      ->assertJsonPath('data.requires_payment', true)
      ->assertJsonPath('data.enrollment.status', 'pending_payment');

    $enrollmentId = $enroll->json('data.enrollment.id');
    $enrollment = Enrollment::query()->where('uuid', $enrollmentId)->firstOrFail();
    $this->assertSame(35.0, (float) $enrollment->price_paid);

    $checkout = $this->actingAs($learner)
      ->postJson("/api/v1/learner/enrollments/{$enrollmentId}/checkout", [
        'payment_method' => 'offline',
        'country' => 'nigeria',
      ])
      ->assertCreated()
      ->json('data');

    $this->assertSame('instructions', $checkout['checkout']['type'] ?? null);
    $orderId = $checkout['order']['id'];

    $order = CourseOrder::query()->where('uuid', $orderId)->firstOrFail();
    $this->assertSame(CourseOrderStatus::AwaitingPayment, $order->status);
    $this->assertNotNull($order->donation_id);
    $this->assertNotNull($order->invoice);

    $this->actingAs($this->admin)
      ->postJson("/api/v1/lms/orders/{$orderId}/confirm")
      ->assertOk()
      ->assertJsonPath('data.order.status', 'paid');

    $enrollment->refresh();
    $this->assertSame(EnrollmentStatus::Active, $enrollment->status);
    $this->assertSame(1, CourseCoupon::query()->where('code', 'SAVE5')->value('redeemed_count'));

    $order->refresh();
    $this->assertTrue($order->invoices()->where('type', 'receipt')->exists());

    $this->actingAs($this->admin)
      ->postJson("/api/v1/lms/orders/{$orderId}/refund", [
        'reason' => 'Learner withdrew',
      ])
      ->assertOk()
      ->assertJsonPath('data.order.status', 'refunded');

    $enrollment->refresh();
    $this->assertSame(EnrollmentStatus::Cancelled, $enrollment->status);
  }

  public function test_admin_can_list_orders(): void
  {
    $this->getJson('/api/v1/lms/orders')
      ->assertOk()
      ->assertJsonStructure(['data' => ['data', 'meta']]);
  }

  public function test_paystack_checkout_uses_donation_gateway(): void
  {
    $course = Course::query()->create([
      'title' => 'Gateway Course',
      'slug' => 'gateway-course',
      'status' => CourseStatus::Published,
      'published_at' => now(),
      'is_free' => false,
      'public_price' => 20,
      'currency' => 'USD',
    ]);

    $learner = $this->memberUser();
    $enrollmentId = $this->actingAs($learner)
      ->postJson('/api/v1/public/courses/gateway-course/enroll')
      ->assertCreated()
      ->json('data.enrollment.id');

    $checkout = $this->actingAs($learner)
      ->postJson("/api/v1/learner/enrollments/{$enrollmentId}/checkout", [
        'payment_method' => 'paystack',
        'country' => 'nigeria',
      ])
      ->assertCreated()
      ->json('data.checkout');

    $this->assertContains($checkout['type'] ?? null, ['redirect', 'instructions']);
  }
}
