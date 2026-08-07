<?php

declare(strict_types=1);

namespace App\Modules\Donations\Http\Controllers\Api\V1\Public;

use App\Http\Controllers\Api\V1\ApiController;
use App\Modules\Cms\Models\CmsCountry;
use App\Modules\Donations\Http\Resources\DonationResource;
use App\Modules\Donations\Models\CountryPaymentMethod;
use App\Modules\Donations\Models\DonationBankAccount;
use App\Modules\Donations\Models\DonationFund;
use App\Modules\Donations\Services\DonationCheckoutService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class PublicDonationController extends ApiController
{
  public function funds(): JsonResponse
  {
    $funds = DonationFund::query()
      ->where('is_active', true)
      ->orderBy('sort_order')
      ->get()
      ->map(fn (DonationFund $fund) => [
        'id' => $fund->uuid,
        'slug' => $fund->slug,
        'name' => $fund->name,
        'type' => $fund->type->value,
        'description' => $fund->description,
      ]);

    return $this->responder->success(data: ['funds' => $funds], message: 'Donation funds retrieved.');
  }

  public function methods(Request $request): JsonResponse
  {
    $validated = $request->validate([
      'country' => ['required', 'string'],
    ]);

    $country = CmsCountry::query()->where('slug', $validated['country'])->first()
      ?? CmsCountry::query()->where('uuid', $validated['country'])->firstOrFail();

    $methods = CountryPaymentMethod::query()
      ->where('country_id', $country->id)
      ->where('is_enabled', true)
      ->orderBy('sort_order')
      ->get()
      ->map(fn (CountryPaymentMethod $method) => [
        'id' => $method->uuid,
        'method' => $method->method->value,
        'label' => $method->label ?: str_replace('_', ' ', ucfirst($method->method->value)),
        'provider_key' => $method->provider_key,
        'config' => $method->config,
      ]);

    $banks = DonationBankAccount::query()
      ->where('country_id', $country->id)
      ->where('is_active', true)
      ->orderBy('sort_order')
      ->get()
      ->map(fn (DonationBankAccount $account) => [
        'id' => $account->uuid,
        'bank_name' => $account->bank_name,
        'account_name' => $account->account_name,
        'account_number' => $account->account_number,
        'routing_number' => $account->routing_number,
        'swift_code' => $account->swift_code,
        'iban' => $account->iban,
        'currency' => $account->currency,
        'instructions' => $account->instructions,
      ]);

    return $this->responder->success(
      data: [
        'country' => ['id' => $country->uuid, 'slug' => $country->slug, 'name' => $country->name],
        'methods' => $methods,
        'bank_accounts' => $banks,
      ],
      message: 'Country payment methods retrieved.',
    );
  }

  public function checkout(Request $request, DonationCheckoutService $service): JsonResponse
  {
    $validated = $request->validate([
      'amount' => ['required', 'numeric', 'min:1'],
      'currency' => ['required', 'string', 'size:3'],
      'country' => ['required_without:country_id', 'nullable', 'string'],
      'country_id' => ['required_without:country', 'nullable', 'string'],
      'payment_method' => ['required', 'string', Rule::in([
        'bank_account', 'card', 'flutterwave', 'paystack', 'stripe', 'paypal', 'offline', 'wire', 'crypto',
      ])],
      'fund_id' => ['nullable', 'string'],
      'fund_type' => ['nullable', 'string', Rule::in(['general', 'mission', 'projects', 'events', 'building', 'scholarship'])],
      'frequency' => ['nullable', 'string', Rule::in(['one_time', 'monthly', 'quarterly', 'yearly'])],
      'is_anonymous' => ['sometimes', 'boolean'],
      'needs_tax_receipt' => ['sometimes', 'boolean'],
      'donor_name' => ['nullable', 'string', 'max:255'],
      'name' => ['nullable', 'string', 'max:255'],
      'donor_email' => ['nullable', 'email', 'max:255'],
      'email' => ['nullable', 'email', 'max:255'],
      'donor_phone' => ['nullable', 'string', 'max:50'],
      'phone' => ['nullable', 'string', 'max:50'],
      'ministry' => ['nullable', 'string', 'max:120'],
      'notes' => ['nullable', 'string', 'max:2000'],
    ]);

    $result = $service->checkout($validated, $request, $request->user());

    return $this->responder->success(
      data: [
        'donation' => new DonationResource($result['donation']),
        'checkout' => $result['checkout'],
      ],
      message: 'Donation checkout created.',
      status: 201,
    );
  }

  public function webhook(string $provider, Request $request, DonationCheckoutService $service): JsonResponse
  {
    $donation = $service->handleWebhook($provider, $request);

    return $this->responder->success(
      data: ['donation' => new DonationResource($donation)],
      message: 'Webhook processed.',
    );
  }
}
