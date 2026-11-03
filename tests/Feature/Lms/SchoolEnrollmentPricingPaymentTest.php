<?php

declare(strict_types=1);

namespace Tests\Feature\Lms;

use App\Enums\MemberApprovalStatus;
use App\Enums\MemberStatus;
use App\Models\Member;
use App\Models\User;
use App\Modules\Donations\Enums\DonationStatus;
use App\Modules\Donations\Models\Donation;
use App\Modules\Donations\Models\PaymentProviderConfig;
use App\Modules\Lms\Enums\CourseOrderStatus;
use App\Modules\Lms\Enums\EnrollmentStatus;
use App\Modules\Lms\Enums\SchoolStatus;
use App\Modules\Lms\Models\LmsSchool;
use App\Modules\Lms\Models\SchoolEnrollment;
use App\Modules\Lms\Models\SchoolOrder;
use Database\Seeders\CmsSeeder;
use Database\Seeders\CommunicationSeeder;
use Database\Seeders\DonationsSeeder;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\FakesPayPal;
use Tests\Feature\Iam\IamTestCase;

final class SchoolEnrollmentPricingPaymentTest extends IamTestCase
{
  use FakesPayPal;

  private const CAPTURE = '/api/v1/public/donations/paypal/capture';

  protected function setUp(): void
  {
    parent::setUp();
    Mail::fake();
    $this->seed([CmsSeeder::class, DonationsSeeder::class, CommunicationSeeder::class]);
    $this->fakePayPal();
  }

  // ── Pricing ────────────────────────────────────────────────────────────

  public function test_approved_full_member_receives_subsidized_price_everywhere(): void
  {
    $school = $this->school('member-school', 40, 100);
    $learner = $this->learnerWithMembership(MemberApprovalStatus::Approved, MemberStatus::Active);

    $this->actingAs($learner)->getJson('/api/v1/public/schools/member-school')->assertOk()
      ->assertJsonPath('data.school.viewer_pricing.amount', 40)
      ->assertJsonPath('data.school.viewer_pricing.pricing_category', 'full_member_subsidized')
      ->assertJsonPath('data.school.viewer_pricing.pricing_label', 'Full Member Subsidized Price')
      ->assertJsonPath('data.school.viewer_pricing.eligible_for_member_price', true);
    $listing = collect($this->actingAs($learner)->getJson('/api/v1/public/schools')->assertOk()->json('data.data'))->firstWhere('slug', 'member-school');
    $this->assertEquals(40, $listing['viewer_pricing']['amount']);

    [$enrollmentId, $checkout] = $this->enrollAndCheckout($learner, 'member-school', expectAmount: 40);
    $checkout->assertJsonPath('data.order.pricing_category', 'full_member_subsidized')
      ->assertJsonPath('data.order.membership_status', 'approved_member')
      ->assertJsonPath('data.order.payment_purpose', 'school_enrollment');

    $order = SchoolOrder::query()->where('uuid', $checkout->json('data.order.id'))->firstOrFail();
    $this->assertSame('40.00', $this->paypalOrders[$order->provider_intent_id]['amount']);
    $this->assertSame('USD', $this->paypalOrders[$order->provider_intent_id]['currency']);
    $this->assertSame(60.0, (float) $order->discount_amount);
    $this->assertSame('full_member_subsidized', $order->donation->metadata['pricing_category']);
    $this->assertSame($school->id, $order->school_id);
  }

  public function test_visitor_receives_standard_price(): void
  {
    $this->school('visitor-school', 40, 100);

    $this->app['auth']->forgetGuards();
    $this->getJson('/api/v1/public/schools/visitor-school', ['Authorization' => ''])->assertOk()
      ->assertJsonPath('data.school.viewer_pricing.amount', 100)
      ->assertJsonPath('data.school.viewer_pricing.pricing_label', 'Standard Enrollment Price')
      ->assertJsonPath('data.school.viewer_pricing.eligible_for_member_price', false);

    [, $checkout] = $this->enrollAndCheckout($this->memberUser(), 'visitor-school', expectAmount: 100);
    $checkout->assertJsonPath('data.order.pricing_category', 'standard')
      ->assertJsonPath('data.order.membership_status', 'visitor');
  }

  /**
   * @return iterable<string, array{MemberApprovalStatus, MemberStatus}>
   */
  public static function ineligibleMemberships(): iterable
  {
    yield 'pending' => [MemberApprovalStatus::Pending, MemberStatus::PendingReview];
    yield 'under review' => [MemberApprovalStatus::UnderReview, MemberStatus::UnderReview];
    yield 'rejected' => [MemberApprovalStatus::Rejected, MemberStatus::Rejected];
    yield 'approved but suspended' => [MemberApprovalStatus::Approved, MemberStatus::Suspended];
    yield 'approved but inactive' => [MemberApprovalStatus::Approved, MemberStatus::Inactive];
  }

  #[DataProvider('ineligibleMemberships')]
  public function test_non_approved_or_inactive_memberships_pay_standard_price(MemberApprovalStatus $approval, MemberStatus $status): void
  {
    $this->school('gated-school', 40, 100);
    $learner = $this->learnerWithMembership($approval, $status);

    $this->actingAs($learner)->getJson('/api/v1/public/schools/gated-school')->assertOk()
      ->assertJsonPath('data.school.viewer_pricing.amount', 100)
      ->assertJsonPath('data.school.viewer_pricing.eligible_for_member_price', false);

    [, $checkout] = $this->enrollAndCheckout($learner, 'gated-school', expectAmount: 100);
    $checkout->assertJsonPath('data.order.pricing_category', 'standard');
    $this->assertStringStartsWith('member_', (string) $checkout->json('data.order.membership_status'));
  }

  public function test_approval_change_reprices_the_next_checkout_and_supersedes_the_stale_order(): void
  {
    $this->school('upgrade-school', 40, 100);
    $learner = $this->memberUser();
    $member = Member::factory()->create(['user_id' => $learner->id, 'approval_status' => MemberApprovalStatus::Pending, 'status' => MemberStatus::PendingReview]);

    [$enrollmentId, $first] = $this->enrollAndCheckout($learner, 'upgrade-school', expectAmount: 100);
    $staleOrder = SchoolOrder::query()->where('uuid', $first->json('data.order.id'))->firstOrFail();

    $member->forceFill(['approval_status' => MemberApprovalStatus::Approved, 'status' => MemberStatus::Active])->save();

    $second = $this->actingAs($learner)->postJson("/api/v1/learner/school-enrollments/{$enrollmentId}/checkout", [])->assertCreated();
    $second->assertJsonPath('data.order.amount', 40)->assertJsonPath('data.order.pricing_category', 'full_member_subsidized');
    $this->assertNotSame($staleOrder->uuid, $second->json('data.order.id'));

    $staleOrder->refresh();
    $this->assertSame(CourseOrderStatus::Cancelled, $staleOrder->status);
    $this->assertSame(DonationStatus::Cancelled, $staleOrder->donation->fresh()->status);
    $this->assertSame(40.0, (float) SchoolEnrollment::query()->where('uuid', $enrollmentId)->value('price_paid'));

    // A capture against the superseded PayPal order must not activate the enrollment.
    $this->postJson(self::CAPTURE, ['token' => $staleOrder->provider_intent_id, 'donation_id' => $staleOrder->donation->uuid])
      ->assertStatus(422)->assertSee('PAYMENT_NOT_PAYABLE');
    $this->assertSame(0, $this->paypalCaptureCalls);

    // Even if PayPal reports the stale order as paid via webhook, the superseded order grants nothing.
    PaymentProviderConfig::query()->where('provider', 'paypal')->update(['webhook_secret' => 'test-hook-secret']);
    $this->postJson('/api/v1/public/donations/webhooks/paypal', [
      'event_type' => 'PAYMENT.CAPTURE.COMPLETED',
      'resource' => [
        'id' => 'CAPTURE-STALE',
        'custom_id' => $staleOrder->donation->uuid,
        'amount' => ['value' => '100.00', 'currency_code' => 'USD'],
        'supplementary_data' => ['related_ids' => ['order_id' => $staleOrder->provider_intent_id]],
      ],
    ], ['X-Donations-Webhook-Secret' => 'test-hook-secret'])->assertOk();
    $this->assertSame(EnrollmentStatus::PendingPayment, SchoolEnrollment::query()->where('uuid', $enrollmentId)->first()->status);
    $this->assertSame(CourseOrderStatus::Cancelled, $staleOrder->fresh()->status);
  }

  public function test_client_supplied_prices_and_non_paypal_methods_are_rejected(): void
  {
    $this->school('tamper-school', 40, 100);
    $learner = $this->memberUser();
    $enrollmentId = $this->actingAs($learner)->postJson('/api/v1/public/schools/tamper-school/enroll')->assertCreated()->json('data.enrollment.id');
    $url = "/api/v1/learner/school-enrollments/{$enrollmentId}/checkout";

    foreach (['amount' => 1, 'price' => 1, 'currency' => 'NGN', 'pricing_category' => 'full_member_subsidized', 'learner_type' => 'member'] as $field => $value) {
      $this->actingAs($learner)->postJson($url, [$field => $value])->assertStatus(422)->assertJsonValidationErrors($field);
    }
    foreach (['bank_account', 'offline', 'paystack', 'stripe'] as $method) {
      $this->actingAs($learner)->postJson($url, ['payment_method' => $method])->assertStatus(422)->assertJsonValidationErrors('payment_method');
    }
    $this->assertSame(0, SchoolOrder::query()->count());
    $this->assertSame(0, Donation::query()->count());
  }

  public function test_school_price_change_does_not_rewrite_historical_paid_orders(): void
  {
    $school = $this->school('history-school', 40, 100);
    $learner = $this->memberUser();
    [$enrollmentId, $checkout] = $this->enrollAndCheckout($learner, 'history-school', expectAmount: 100);
    $this->capture($checkout)->assertOk()->assertJsonPath('data.payment_status', 'confirmed');

    $school->forceFill(['public_price' => 250])->save();

    $order = SchoolOrder::query()->where('uuid', $checkout->json('data.order.id'))->firstOrFail();
    $this->assertSame(100.0, (float) $order->amount);
    $this->assertSame(100.0, (float) SchoolEnrollment::query()->where('uuid', $enrollmentId)->value('price_paid'));
    $this->getJson('/api/v1/public/schools/history-school')->assertJsonPath('data.school.viewer_pricing.amount', 250);
  }

  public function test_free_school_enrolls_without_payment_for_members_and_visitors(): void
  {
    $this->school('free-school', 0, 0);
    $member = $this->learnerWithMembership(MemberApprovalStatus::Approved, MemberStatus::Active);
    $this->actingAs($member)->postJson('/api/v1/public/schools/free-school/enroll')->assertCreated()
      ->assertJsonPath('data.requires_payment', false)
      ->assertJsonPath('data.enrollment.status', 'active');
    $this->actingAs($this->memberUser())->postJson('/api/v1/public/schools/free-school/enroll')->assertCreated()
      ->assertJsonPath('data.requires_payment', false);
    $this->assertSame(0, SchoolOrder::query()->count());
  }

  // ── Payment routing and classification ─────────────────────────────────

  public function test_checkout_uses_global_paypal_and_never_regional_bank_details(): void
  {
    $this->school('global-school', 40, 100);
    [, $checkout] = $this->enrollAndCheckout($this->memberUser(), 'global-school', expectAmount: 100);

    $checkout->assertJsonPath('data.checkout.type', 'redirect');
    $this->assertStringStartsWith('https://www.sandbox.paypal.com/', $checkout->json('data.checkout.redirect_url'));
    $body = $checkout->getContent();
    foreach (['63233103893', 'FNB', 'First National Bank', 'bank_account'] as $needle) {
      $this->assertStringNotContainsString($needle, $body);
    }

    $order = SchoolOrder::query()->where('uuid', $checkout->json('data.order.id'))->firstOrFail();
    $donation = $order->donation;
    $this->assertNull($donation->country_id);
    $this->assertSame(Donation::PURPOSE_SCHOOL_ENROLLMENT, $donation->payment_purpose);
    $sent = $this->paypalOrders[$order->provider_intent_id];
    $this->assertSame($donation->uuid, $sent['custom_id']);
    $this->assertStringContainsString('/schools/global-school', $sent['return_url']);
    $this->assertStringContainsString('School enrollment', $sent['description']);
  }

  public function test_school_payments_are_classified_and_reported_separately_from_donations(): void
  {
    $this->school('report-school', 40, 100);
    [, $checkout] = $this->enrollAndCheckout($this->memberUser(), 'report-school', expectAmount: 100);
    $this->capture($checkout)->assertOk();

    $gift = $this->postJson('/api/v1/public/donations/checkout', [
      'global' => true, 'payment_method' => 'paypal', 'amount' => 25, 'currency' => 'USD',
      'donor_name' => 'Giver', 'donor_email' => 'giver@example.com', 'frequency' => 'one_time',
    ]);
    if ($gift->status() === 201 || $gift->status() === 200) {
      $giftDonation = Donation::query()->where('uuid', $gift->json('data.donation.id'))->first();
      $this->assertSame(Donation::PURPOSE_DONATION, $giftDonation?->payment_purpose);
    }
    $plainDonation = Donation::query()->create([
      'reference' => 'DON-TEST-1', 'donor_name' => 'Giver', 'donor_email' => 'giver2@example.com',
      'amount' => 30, 'currency' => 'USD', 'status' => DonationStatus::Succeeded, 'payment_method' => 'paypal',
      'frequency' => 'one_time', 'paid_at' => now(),
    ]);
    $this->assertSame(Donation::PURPOSE_DONATION, $plainDonation->payment_purpose);

    Sanctum::actingAs($this->admin);
    $school = $this->getJson('/api/v1/donations?payment_purpose=school_enrollment')->assertOk();
    $this->assertCount(1, $school->json('data.data'));
    $school->assertJsonPath('data.data.0.payment_purpose', 'school_enrollment')
      ->assertJsonPath('data.data.0.purpose_reference.pricing_category', 'standard');
    $this->assertNotEmpty($school->json('data.data.0.purpose_reference.school_order_id'));
    $donationsOnly = collect($this->getJson('/api/v1/donations?payment_purpose=donation')->assertOk()->json('data.data'));
    $this->assertTrue($donationsOnly->every(fn ($row) => $row['payment_purpose'] === 'donation'));

    $analytics = $this->getJson('/api/v1/donations/analytics')->assertOk()->json('data.analytics');
    $this->assertSame('donation', $analytics['scope']);
    $this->assertEquals(30, $analytics['total_amount']);
    $byPurpose = collect($analytics['by_purpose'])->keyBy('payment_purpose');
    $this->assertEquals(100, $byPurpose['school_enrollment']['amount']);

    $orders = $this->getJson('/api/v1/lms/school-orders?pricing_category=standard&payment_method=paypal&status=paid&date_from='.now()->subDay()->toDateString())->assertOk();
    $orders->assertJsonPath('data.summary.payment_purpose', 'school_enrollment')
      ->assertJsonPath('data.summary.paid_orders', 1);
    $this->assertCount(1, $orders->json('data.data'));
    $this->assertCount(0, $this->getJson('/api/v1/lms/school-orders?pricing_category=full_member_subsidized')->json('data.data'));
  }

  // ── Verification and idempotency ───────────────────────────────────────

  public function test_verified_capture_activates_exactly_once_and_records_provider_ids(): void
  {
    $this->school('once-school', 40, 100);
    $learner = $this->memberUser();
    [$enrollmentId, $checkout] = $this->enrollAndCheckout($learner, 'once-school', expectAmount: 100);

    $this->capture($checkout)->assertOk()
      ->assertJsonPath('data.payment_status', 'confirmed')
      ->assertJsonPath('data.payment_purpose', 'school_enrollment')
      ->assertJsonPath('data.school_order.enrollment_status', 'active');
    $this->capture($checkout)->assertOk()->assertJsonPath('data.payment_status', 'confirmed');

    $this->assertSame(1, $this->paypalCaptureCalls);
    $order = SchoolOrder::query()->where('uuid', $checkout->json('data.order.id'))->firstOrFail();
    $this->assertSame(CourseOrderStatus::Paid, $order->status);
    $this->assertSame('CAPTURE-'.$order->provider_intent_id, $order->provider_transaction_id);
    $this->assertSame('paypal', $order->payment_method);
    $this->assertNotNull($order->paid_at);
    $this->assertSame(1, SchoolEnrollment::query()->where('user_id', $learner->id)->count());
    $this->assertSame(EnrollmentStatus::Active, SchoolEnrollment::query()->where('uuid', $enrollmentId)->first()->status);

    $this->actingAs($learner)->postJson("/api/v1/learner/school-enrollments/{$enrollmentId}/checkout", [])->assertStatus(422);
  }

  public function test_pending_and_declined_captures_do_not_grant_access(): void
  {
    $this->school('pending-school', 40, 100);
    [$enrollmentId, $checkout] = $this->enrollAndCheckout($this->memberUser(), 'pending-school', expectAmount: 100);
    $orderId = $this->paypalTokenFor($checkout->json('data.checkout.redirect_url'));

    $this->paypalCaptureStatus[$orderId] = 'PENDING';
    $this->capture($checkout)->assertOk()->assertJsonPath('data.payment_status', 'pending');
    $this->assertSame(EnrollmentStatus::PendingPayment, SchoolEnrollment::query()->where('uuid', $enrollmentId)->first()->status);

    $this->paypalCaptureStatus[$orderId] = 'DECLINED';
    $this->capture($checkout)->assertJsonPath('data.payment_status', 'failed');
    $this->assertNotSame(EnrollmentStatus::Active, SchoolEnrollment::query()->where('uuid', $enrollmentId)->first()->status);
    $this->assertNotSame(CourseOrderStatus::Paid, SchoolOrder::query()->where('uuid', $checkout->json('data.order.id'))->first()->status);
  }

  public function test_tampered_or_mismatched_captures_are_rejected(): void
  {
    $this->school('mismatch-school', 40, 100);
    [$enrollmentId, $checkout] = $this->enrollAndCheckout($this->memberUser(), 'mismatch-school', expectAmount: 100);
    [, $other] = $this->enrollAndCheckout($this->memberUser(), 'mismatch-school', expectAmount: 100);
    $token = $this->paypalTokenFor($checkout->json('data.checkout.redirect_url'));
    $donationId = SchoolOrder::query()->where('uuid', $checkout->json('data.order.id'))->first()->donation->uuid;
    $otherDonationId = SchoolOrder::query()->where('uuid', $other->json('data.order.id'))->first()->donation->uuid;

    // Token from one checkout replayed against another payment.
    $this->postJson(self::CAPTURE, ['token' => $token, 'donation_id' => $otherDonationId])->assertStatus(422);
    $this->postJson(self::CAPTURE, ['token' => 'SANDBOX-UNKNOWN', 'donation_id' => $donationId])->assertStatus(422);

    $this->paypalCaptureOverrides[$token] = ['amount' => '1.00'];
    $this->postJson(self::CAPTURE, ['token' => $token, 'donation_id' => $donationId])->assertStatus(422);
    $this->paypalCaptureOverrides[$token] = ['currency' => 'EUR'];
    $this->postJson(self::CAPTURE, ['token' => $token, 'donation_id' => $donationId])->assertStatus(422);
    $this->paypalCaptureOverrides[$token] = ['custom_id' => $otherDonationId];
    $this->postJson(self::CAPTURE, ['token' => $token, 'donation_id' => $donationId])->assertStatus(422);

    $this->assertSame(EnrollmentStatus::PendingPayment, SchoolEnrollment::query()->where('uuid', $enrollmentId)->first()->status);
    $this->assertNotSame(DonationStatus::Succeeded, Donation::query()->where('uuid', $donationId)->first()->status);
  }

  public function test_webhooks_require_authentication_and_are_idempotent(): void
  {
    PaymentProviderConfig::query()->where('provider', 'paypal')->update(['webhook_secret' => 'test-hook-secret']);
    $this->school('hook-school', 40, 100);
    [$enrollmentId, $checkout] = $this->enrollAndCheckout($this->memberUser(), 'hook-school', expectAmount: 100);
    $order = SchoolOrder::query()->where('uuid', $checkout->json('data.order.id'))->firstOrFail();
    $event = fn (string $amount) => [
      'event_type' => 'PAYMENT.CAPTURE.COMPLETED',
      'resource' => [
        'id' => 'CAPTURE-HOOK-1',
        'custom_id' => $order->donation->uuid,
        'amount' => ['value' => $amount, 'currency_code' => 'USD'],
        'supplementary_data' => ['related_ids' => ['order_id' => $order->provider_intent_id]],
      ],
    ];
    $url = '/api/v1/public/donations/webhooks/paypal';

    $this->postJson($url, $event('100.00'))->assertStatus(401);
    $this->postJson($url, $event('100.00'), ['X-Donations-Webhook-Secret' => 'wrong'])->assertStatus(401);
    $this->postJson($url, $event('5.00'), ['X-Donations-Webhook-Secret' => 'test-hook-secret'])->assertStatus(422);
    $this->assertSame(EnrollmentStatus::PendingPayment, SchoolEnrollment::query()->where('uuid', $enrollmentId)->first()->status);

    $this->postJson($url, $event('100.00'), ['X-Donations-Webhook-Secret' => 'test-hook-secret'])->assertOk();
    $paidAt = $order->fresh()->paid_at;
    $this->postJson($url, $event('100.00'), ['X-Donations-Webhook-Secret' => 'test-hook-secret'])->assertOk();

    $this->assertSame(EnrollmentStatus::Active, SchoolEnrollment::query()->where('uuid', $enrollmentId)->first()->status);
    $this->assertSame(CourseOrderStatus::Paid, $order->fresh()->status);
    $this->assertEquals($paidAt, $order->fresh()->paid_at);
    $this->assertSame(1, SchoolOrder::query()->where('status', CourseOrderStatus::Paid->value)->count());
  }

  public function test_users_cannot_checkout_another_learners_enrollment(): void
  {
    $this->school('owner-school', 40, 100);
    $owner = $this->memberUser();
    $enrollmentId = $this->actingAs($owner)->postJson('/api/v1/public/schools/owner-school/enroll')->assertCreated()->json('data.enrollment.id');

    $this->actingAs($this->memberUser())->postJson("/api/v1/learner/school-enrollments/{$enrollmentId}/checkout", [])->assertForbidden();
    $this->assertSame(0, SchoolOrder::query()->count());
  }

  // ── Helpers ────────────────────────────────────────────────────────────

  private function school(string $slug, int|float|null $memberPrice, int|float|null $publicPrice): LmsSchool
  {
    return LmsSchool::query()->create([
      'uuid' => (string) Str::uuid(),
      'slug' => $slug,
      'title' => Str::headline($slug),
      'status' => SchoolStatus::Published,
      'member_price' => $memberPrice,
      'public_price' => $publicPrice,
      'currency' => 'USD',
      'published_at' => now(),
    ]);
  }

  private function learnerWithMembership(MemberApprovalStatus $approval, MemberStatus $status): User
  {
    $user = $this->memberUser();
    Member::factory()->create(['user_id' => $user->id, 'email' => $user->email, 'approval_status' => $approval, 'status' => $status]);

    return $user;
  }

  /**
   * @return array{0: string, 1: \Illuminate\Testing\TestResponse<\Illuminate\Http\JsonResponse>}
   */
  private function enrollAndCheckout(User $learner, string $slug, float $expectAmount): array
  {
    $enroll = $this->actingAs($learner)->postJson("/api/v1/public/schools/{$slug}/enroll")->assertCreated();
    $enrollmentId = (string) $enroll->json('data.enrollment.id');

    $checkout = $this->actingAs($learner)->postJson("/api/v1/learner/school-enrollments/{$enrollmentId}/checkout", ['payment_method' => 'paypal'])
      ->assertCreated();
    $this->assertEquals($expectAmount, $checkout->json('data.order.amount'));
    $this->assertSame(EnrollmentStatus::PendingPayment, SchoolEnrollment::query()->where('uuid', $enrollmentId)->first()->status);

    return [$enrollmentId, $checkout];
  }

  /**
   * @param  \Illuminate\Testing\TestResponse<\Illuminate\Http\JsonResponse>  $checkout
   * @return \Illuminate\Testing\TestResponse<\Illuminate\Http\JsonResponse>
   */
  private function capture($checkout)
  {
    $order = SchoolOrder::query()->where('uuid', $checkout->json('data.order.id'))->firstOrFail();

    return $this->postJson(self::CAPTURE, [
      'token' => $this->paypalTokenFor($checkout->json('data.checkout.redirect_url')),
      'donation_id' => $order->donation->uuid,
    ]);
  }
}
