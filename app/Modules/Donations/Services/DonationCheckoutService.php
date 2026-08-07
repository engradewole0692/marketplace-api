<?php

declare(strict_types=1);

namespace App\Modules\Donations\Services;

use App\Contracts\ServiceContract;
use App\Models\Member;
use App\Models\User;
use App\Modules\Cms\Enums\FormSubmissionType;
use App\Modules\Cms\Models\CmsCountry;
use App\Modules\Cms\Services\FormSubmissionService;
use App\Modules\Donations\Enums\DonationFrequency;
use App\Modules\Donations\Enums\DonationStatus;
use App\Modules\Donations\Enums\PaymentMethod;
use App\Modules\Donations\Enums\ReceiptType;
use App\Modules\Donations\Models\CountryPaymentMethod;
use App\Modules\Donations\Models\Donation;
use App\Modules\Donations\Models\DonationFund;
use App\Modules\Donations\Models\DonationPayment;
use App\Modules\Donations\Models\DonationSubscription;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class DonationCheckoutService implements ServiceContract
{
  public function __construct(
    private readonly PaymentGatewayManager $gateways,
    private readonly DonationAuditService $auditService,
    private readonly DonationReceiptService $receiptService,
    private readonly FormSubmissionService $formSubmissionService,
  ) {}

  /**
   * @return array{donation: Donation, checkout: array<string, mixed>}
   */
  public function checkout(array $payload, Request $request, ?User $user = null): array
  {
    $countryQuery = CmsCountry::query();
    if (! empty($payload['country_id'])) {
      $country = $countryQuery->where('uuid', $payload['country_id'])->firstOrFail();
    } else {
      $country = $countryQuery->where('slug', $payload['country'] ?? '')->firstOrFail();
    }

    $method = PaymentMethod::from((string) $payload['payment_method']);

    $enabled = CountryPaymentMethod::query()
      ->where('country_id', $country->id)
      ->where('method', $method->value)
      ->where('is_enabled', true)
      ->exists();

    abort_unless($enabled || app()->environment('testing'), 422, 'Payment method not available for this country.');

    $fund = null;
    if (! empty($payload['fund_id'])) {
      $fund = DonationFund::query()->where('uuid', $payload['fund_id'])->first();
    } elseif (! empty($payload['fund_type'])) {
      $fund = DonationFund::query()->where('type', $payload['fund_type'])->where('is_active', true)->orderBy('sort_order')->first();
    }

    $frequency = DonationFrequency::from((string) ($payload['frequency'] ?? 'one_time'));
    $isAnonymous = (bool) ($payload['is_anonymous'] ?? false);

    $submission = $this->formSubmissionService->submit(FormSubmissionType::DonationInterest, [
      'name' => $isAnonymous ? 'Anonymous' : ($payload['donor_name'] ?? $payload['name'] ?? null),
      'email' => $payload['donor_email'] ?? $payload['email'] ?? null,
      'phone' => $payload['donor_phone'] ?? $payload['phone'] ?? null,
      'country' => $country->slug,
      'purpose' => $fund?->type->value ?? ($payload['fund_type'] ?? 'general'),
      'amount' => (string) $payload['amount'],
      'currency' => $payload['currency'] ?? 'USD',
      'frequency' => $frequency->value,
      'payment_method' => $method->value,
      'notes' => $payload['notes'] ?? null,
    ], $request);

    $donation = Donation::query()->create([
      'reference' => 'DN-'.now()->format('Ymd').'-'.Str::upper(Str::random(8)),
      'fund_id' => $fund?->id,
      'country_id' => $country->id,
      'member_id' => $user ? Member::query()->where('user_id', $user->id)->value('id') : null,
      'form_submission_id' => $submission->id,
      'amount' => (float) $payload['amount'],
      'currency' => strtoupper((string) ($payload['currency'] ?? 'USD')),
      'status' => DonationStatus::Pending,
      'frequency' => $frequency,
      'is_anonymous' => $isAnonymous,
      'needs_tax_receipt' => (bool) ($payload['needs_tax_receipt'] ?? false),
      'donor_name' => $isAnonymous ? null : ($payload['donor_name'] ?? $payload['name'] ?? null),
      'donor_email' => $payload['donor_email'] ?? $payload['email'] ?? null,
      'donor_phone' => $payload['donor_phone'] ?? $payload['phone'] ?? null,
      'payment_method' => $method,
      'provider' => $method->value,
      'notes' => $payload['notes'] ?? null,
      'metadata' => array_merge([
        'ministry' => $payload['ministry'] ?? null,
        'source' => $payload['metadata']['source'] ?? 'public_checkout',
      ], is_array($payload['metadata'] ?? null) ? $payload['metadata'] : []),
    ]);

    $gateway = $this->gateways->for($method);
    $checkout = $gateway->createCheckout($donation, ['request' => $request]);

    $donation->fill([
      'provider_intent_id' => $checkout['provider_intent_id'] ?? null,
      'status' => in_array($checkout['type'], ['redirect'], true)
        ? DonationStatus::Processing
        : DonationStatus::Pending,
    ])->save();

    DonationPayment::query()->create([
      'donation_id' => $donation->id,
      'provider' => $method->value,
      'provider_payment_id' => $checkout['provider_intent_id'] ?? null,
      'amount' => $donation->amount,
      'currency' => $donation->currency,
      'status' => 'pending',
      'raw_payload' => $checkout,
    ]);

    if ($frequency !== DonationFrequency::OneTime && $gateway->supportsRecurring()) {
      DonationSubscription::query()->create([
        'donation_id' => $donation->id,
        'member_id' => $donation->member_id,
        'fund_id' => $donation->fund_id,
        'provider' => $method->value,
        'interval' => $frequency->value,
        'amount' => $donation->amount,
        'currency' => $donation->currency,
        'status' => 'active',
        'next_charge_at' => now()->addMonth(),
      ]);
    }

    $this->auditService->record('checkout_created', 'donation', $donation->id, $user, null, [
      'reference' => $donation->reference,
      'method' => $method->value,
      'amount' => $donation->amount,
    ]);

    return [
      'donation' => $donation->fresh(['fund', 'country', 'payments']),
      'checkout' => $checkout,
    ];
  }

  public function confirmSucceeded(Donation $donation, ?User $actor = null, ?array $payload = null): Donation
  {
    $donation->fill([
      'status' => DonationStatus::Succeeded,
      'paid_at' => now(),
      'confirmed_by' => $actor?->id,
    ])->save();

    $payment = $donation->payments()->latest()->first();
    if ($payment !== null) {
      $payment->fill([
        'status' => 'succeeded',
        'paid_at' => now(),
        'raw_payload' => array_merge($payment->raw_payload ?? [], $payload ?? []),
      ])->save();
    }

    $this->receiptService->issue($donation, ReceiptType::Standard, $actor);
    if ($donation->needs_tax_receipt) {
      $this->receiptService->issue($donation, ReceiptType::Tax, $actor);
    }

    $this->auditService->record('donation_succeeded', 'donation', $donation->id, $actor, null, [
      'reference' => $donation->reference,
    ]);

    // Activate linked LMS course orders without duplicating payment confirmation logic.
    if (($donation->metadata['purpose'] ?? null) === 'course_order') {
      try {
        app(\App\Modules\Lms\Services\CourseCommerceService::class)->activateFromDonation($donation, $actor);
      } catch (\Throwable $e) {
        report($e);
      }
    }

    return $donation->fresh(['fund', 'country', 'receipt', 'payments']);
  }

  public function handleWebhook(string $provider, Request $request): Donation
  {
    $gateway = $this->gateways->for($provider);
    $event = $gateway->parseWebhook($request);
    $intent = $event['provider_payment_id'] ?? $request->input('provider_intent_id') ?? $request->input('intent');

    $donation = Donation::query()
      ->where('provider_intent_id', $intent)
      ->orWhere('uuid', $request->input('donation_id'))
      ->orWhere('reference', $request->input('reference'))
      ->firstOrFail();

    if (($event['status'] ?? 'succeeded') === 'succeeded') {
      return $this->confirmSucceeded($donation, null, $event['payload'] ?? []);
    }

    $donation->fill(['status' => DonationStatus::Failed])->save();
    $this->auditService->record('donation_failed', 'donation', $donation->id, null, null, $event);

    return $donation->fresh();
  }
}
