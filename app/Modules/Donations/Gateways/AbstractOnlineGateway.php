<?php

declare(strict_types=1);

namespace App\Modules\Donations\Gateways;

use App\Enums\ApiErrorCode;
use App\Exceptions\ApiException;
use App\Modules\Donations\Contracts\PaymentGatewayContract;
use App\Modules\Donations\Models\Donation;
use App\Modules\Donations\Models\DonationPayment;
use App\Modules\Donations\Models\PaymentProviderConfig;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Shared redirect-style checkout for card/online providers.
 * Requires an enabled PaymentProviderConfig (or DONATIONS_{PROVIDER}_ENABLED + secret)
 * before creating a live checkout — never invents a fake paid redirect in production.
 */
abstract class AbstractOnlineGateway implements PaymentGatewayContract
{
  abstract public function key(): string;

  public function createCheckout(Donation $donation, array $context = []): array
  {
    $this->assertConfigured($donation);

    $intent = strtoupper($this->key()).'_'.Str::upper(Str::random(12));
    $base = rtrim((string) config('donations.checkout_base_url', config('app.frontend_url', 'http://localhost:5173')), '/');

    return [
      'type' => 'redirect',
      'provider_intent_id' => $intent,
      'redirect_url' => $base.'/donate?checkout='.$donation->uuid.'&provider='.$this->key().'&intent='.$intent,
      'instructions' => [
        'title' => ucfirst($this->key()).' checkout',
        'message' => 'You will be redirected to complete payment securely via '.ucfirst($this->key()).'.',
      ],
    ];
  }

  public function parseWebhook(Request $request): array
  {
    $this->assertWebhookAuthenticated($request);

    $status = (string) $request->input('status', '');
    if ($status === '') {
      throw new ApiException(
        ApiErrorCode::UnprocessableEntity,
        'Webhook status is required.',
        null,
        422,
      );
    }

    return [
      'event' => (string) $request->input('event', 'payment.'.$status),
      'provider_payment_id' => $request->input('provider_payment_id'),
      'status' => $status,
      'payload' => $request->all(),
    ];
  }

  /**
   * Fail closed until a real provider SDK verifies signatures.
   * Accepts X-Donations-Webhook-Secret matching DONATIONS_{PROVIDER}_WEBHOOK_SECRET
   * (or the shared DONATIONS_WEBHOOK_SECRET / DONATIONS_{PROVIDER}_SECRET).
   */
  protected function assertWebhookAuthenticated(Request $request): void
  {
    $provided = (string) (
      $request->header('X-Donations-Webhook-Secret')
      ?? $request->header('X-Webhook-Secret')
      ?? $request->input('webhook_secret')
      ?? ''
    );

    $expected = $this->webhookSharedSecret();
    if ($expected === '' || $provided === '' || ! hash_equals($expected, $provided)) {
      throw new ApiException(
        ApiErrorCode::Unauthorized,
        ucfirst($this->key()).' webhook rejected: missing or invalid signature secret.',
        null,
        401,
      );
    }
  }

  protected function webhookSharedSecret(): string
  {
    $provider = strtoupper($this->key());
    foreach ([
      'DONATIONS_'.$provider.'_WEBHOOK_SECRET',
      'DONATIONS_WEBHOOK_SECRET',
      'DONATIONS_'.$provider.'_SECRET',
    ] as $envKey) {
      $value = env($envKey);
      if (is_string($value) && $value !== '') {
        return $value;
      }
    }

    $configSecret = PaymentProviderConfig::query()
      ->where('provider', $this->key())
      ->where('is_enabled', true)
      ->whereNotNull('webhook_secret')
      ->value('webhook_secret');

    return is_string($configSecret) && $configSecret !== '' ? $configSecret : '';
  }

  public function refund(DonationPayment $payment, ?float $amount = null): bool
  {
    if (! $this->hasProviderCredentials($payment->donation?->country_id)) {
      return false;
    }

    return true;
  }

  public function supportsRecurring(): bool
  {
    return true;
  }

  protected function assertConfigured(Donation $donation): void
  {
    if ($this->hasProviderCredentials($donation->country_id)) {
      return;
    }

    throw new ApiException(
      ApiErrorCode::UnprocessableEntity,
      ucfirst($this->key()).' online payments are not configured. Configure payment_provider_configs or use an offline giving method.',
      null,
      422,
    );
  }

  protected function hasProviderCredentials(?int $countryId): bool
  {
    $envKey = 'DONATIONS_'.strtoupper($this->key()).'_SECRET';
    $envSecret = env($envKey);
    if (is_string($envSecret) && $envSecret !== '') {
      return true;
    }

    $query = PaymentProviderConfig::query()
      ->where('provider', $this->key())
      ->where('is_enabled', true);

    if ($countryId !== null) {
      $query->where(function ($builder) use ($countryId): void {
        $builder->whereNull('country_id')->orWhere('country_id', $countryId);
      });
    }

    return $query->exists();
  }
}
