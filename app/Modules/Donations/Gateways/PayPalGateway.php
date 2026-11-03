<?php

declare(strict_types=1);

namespace App\Modules\Donations\Gateways;

use App\Enums\ApiErrorCode;
use App\Exceptions\ApiException;
use App\Modules\Donations\Models\Donation;
use App\Modules\Donations\Models\DonationPayment;
use App\Modules\Donations\Models\PaymentProviderConfig;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * PayPal Orders v2 implementation.
 *
 * Flow:
 *  1. createCheckout()  → Creates a PayPal order, returns approval URL.
 *  2. captureOrder()    → Called after buyer approves; captures funds server-side.
 *  3. parseWebhook()    → Validates PayPal webhook signature + returns normalised event.
 *
 * Credentials (in priority order):
 *  a. payment_provider_configs row   (is_enabled=true, provider='paypal')
 *  b. DONATIONS_PAYPAL_CLIENT_ID / DONATIONS_PAYPAL_CLIENT_SECRET env vars
 *
 * Sandbox vs live mode is controlled by the `is_live` flag on the config row,
 * or the DONATIONS_PAYPAL_MODE env var ('sandbox' | 'live').
 */
final class PayPalGateway extends AbstractOnlineGateway
{
  private const SANDBOX_URL = 'https://api-m.sandbox.paypal.com';

  private const LIVE_URL = 'https://api-m.paypal.com';

  public function key(): string
  {
    return 'paypal';
  }

  // ──────────────────────────────────────────────────
  //  Public gateway contract
  // ──────────────────────────────────────────────────

  public function createCheckout(Donation $donation, array $context = []): array
  {
    [$clientId, $clientSecret] = $this->credentials($donation->country_id);

    if ($clientId === '' || $clientSecret === '') {
      throw new ApiException(
        ApiErrorCode::UnprocessableEntity,
        'PayPal payments are not configured. Add a PayPal configuration or contact support.',
        null,
        422,
      );
    }

    $token = $this->getAccessToken($clientId, $clientSecret, $donation->country_id);

    $base = rtrim((string) config('app.url', 'http://localhost'), '/');
    $frontendBase = rtrim((string) config('donations.checkout_base_url', config('app.frontend_url', $base)), '/');

    $meta = is_array($donation->metadata) ? $donation->metadata : [];
    $returnPath = $this->safeReturnPath($meta['return_path'] ?? null);
    $returnUrl = $frontendBase.$returnPath.'?checkout='.$donation->uuid.'&provider=paypal&status=approved';
    $cancelUrl = $frontendBase.$returnPath.'?checkout='.$donation->uuid.'&provider=paypal&status=cancelled';
    $description = is_string($meta['description'] ?? null) && $meta['description'] !== ''
      ? mb_substr($meta['description'], 0, 127)
      : ($donation->fund?->name ?? 'Donation');

    $response = Http::withToken($token)
      ->withHeaders(['PayPal-Request-Id' => 'checkout-'.$donation->uuid])
      ->post($this->apiUrl($donation->country_id).'/v2/checkout/orders', [
        'intent' => 'CAPTURE',
        'purchase_units' => [[
          'reference_id' => $donation->reference,
          'invoice_id' => $donation->reference,
          'description' => $description,
          'amount' => [
            'currency_code' => strtoupper($donation->currency),
            'value' => number_format((float) $donation->amount, 2, '.', ''),
          ],
          'custom_id' => $donation->uuid,
        ]],
        'application_context' => [
          'return_url' => $returnUrl,
          'cancel_url' => $cancelUrl,
          'brand_name' => (string) config('app.name', 'Marketplace'),
          'user_action' => 'PAY_NOW',
          'shipping_preference' => 'NO_SHIPPING',
        ],
      ]);

    if (! $response->successful()) {
      Log::warning('PayPal create order failed', [
        'donation' => $donation->uuid,
        'status' => $response->status(),
        'body' => $response->json(),
      ]);

      throw new ApiException(
        ApiErrorCode::UnprocessableEntity,
        'Unable to create PayPal order. Please try again.',
        null,
        422,
      );
    }

    $order = $response->json();
    $orderId = $order['id'] ?? null;
    $approvalUrl = collect($order['links'] ?? [])->firstWhere('rel', 'approve')['href'] ?? null;

    if ($orderId === null || $approvalUrl === null) {
      throw new ApiException(
        ApiErrorCode::UnprocessableEntity,
        'PayPal did not return a valid order.',
        null,
        422,
      );
    }

    return [
      'type' => 'redirect',
      'provider_intent_id' => $orderId,
      'redirect_url' => $approvalUrl,
      'instructions' => [
        'title' => 'PayPal Checkout',
        'message' => 'You will be redirected to PayPal to complete your payment securely.',
      ],
    ];
  }

  /**
   * Capture a PayPal order that the buyer has already approved.
   * Call this from the return-URL handler (after buyer comes back) or from
   * a webhook.  Idempotent — if the order is already captured this is a no-op.
   *
   * capture_status is the authoritative result: only "completed" means funds were captured.
   * When PayPal reports the order as already captured, the order is fetched again so the result is
   * always read from PayPal rather than assumed.
   *
   * @return array{captured: bool, order_id: string, status: string, capture_status: string, capture_id: ?string, amount: ?string, currency: ?string, custom_id: ?string, reference_id: ?string, payer: array<string,mixed>|null}
   */
  public function captureOrder(string $orderId, ?int $countryId = null): array
  {
    [$clientId, $clientSecret] = $this->credentials($countryId);
    $token = $this->getAccessToken($clientId, $clientSecret, $countryId);

    $response = Http::withToken($token)
      ->withHeaders(['PayPal-Request-Id' => 'capture-'.$orderId])
      ->post($this->apiUrl($countryId)."/v2/checkout/orders/{$orderId}/capture", []);

    $order = $response->json();
    if (! $response->successful()) {
      $alreadyCaptured = ($order['details'][0]['issue'] ?? '') === 'ORDER_ALREADY_CAPTURED';

      if (! $alreadyCaptured) {
        Log::warning('PayPal capture failed', [
          'order_id' => $orderId,
          'status' => $response->status(),
          'body' => $order,
        ]);

        throw new ApiException(
          ApiErrorCode::UnprocessableEntity,
          'PayPal payment capture failed. Please contact support.',
          null,
          422,
        );
      }

      $lookup = Http::withToken($token)->get($this->apiUrl($countryId)."/v2/checkout/orders/{$orderId}");
      if (! $lookup->successful()) {
        throw new ApiException(ApiErrorCode::UnprocessableEntity, 'Could not verify the PayPal order. Please contact support.', null, 422);
      }
      $order = $lookup->json();
    }

    return $this->normalizeOrder($orderId, is_array($order) ? $order : []);
  }

  /**
   * @param  array<string, mixed>  $order
   * @return array{captured: bool, order_id: string, status: string, capture_status: string, capture_id: ?string, amount: ?string, currency: ?string, custom_id: ?string, reference_id: ?string, payer: array<string,mixed>|null}
   */
  private function normalizeOrder(string $orderId, array $order): array
  {
    $unit = $order['purchase_units'][0] ?? [];
    $capture = $unit['payments']['captures'][0] ?? [];
    $captureStatus = strtolower((string) ($capture['status'] ?? ''));

    return [
      'captured' => $captureStatus === 'completed',
      'order_id' => (string) ($order['id'] ?? $orderId),
      'status' => strtolower((string) ($order['status'] ?? '')),
      'capture_status' => $captureStatus,
      'capture_id' => isset($capture['id']) ? (string) $capture['id'] : null,
      'amount' => isset($capture['amount']['value']) ? (string) $capture['amount']['value'] : (isset($unit['amount']['value']) ? (string) $unit['amount']['value'] : null),
      'currency' => isset($capture['amount']['currency_code']) ? (string) $capture['amount']['currency_code'] : (isset($unit['amount']['currency_code']) ? (string) $unit['amount']['currency_code'] : null),
      'custom_id' => isset($capture['custom_id']) ? (string) $capture['custom_id'] : (isset($unit['custom_id']) ? (string) $unit['custom_id'] : null),
      'reference_id' => isset($unit['reference_id']) ? (string) $unit['reference_id'] : null,
      'payer' => $order['payer'] ?? null,
    ];
  }

  private function safeReturnPath(mixed $path): string
  {
    if (! is_string($path) || preg_match('#^/(?!/)[A-Za-z0-9/_\-]*$#', $path) !== 1) {
      return '/donate';
    }

    return rtrim($path, '/') ?: '/donate';
  }

  /**
   * Verify a PayPal webhook using their Webhook Verification API.
   *
   * PayPal docs: POST /v1/notifications/verify-webhook-signature
   */
  public function parseWebhook(Request $request): array
  {
    $this->verifyPayPalWebhookSignature($request);

    $event = (string) $request->input('event_type', '');
    $resource = (array) $request->input('resource', []);
    // PAYMENT.CAPTURE.* resources are captures (id = capture id); the order id is in related_ids.
    $orderId = str_starts_with($event, 'PAYMENT.CAPTURE')
      ? ($resource['supplementary_data']['related_ids']['order_id'] ?? null)
      : ($resource['id'] ?? null);

    $status = match (true) {
      str_starts_with($event, 'CHECKOUT.ORDER.APPROVED') => 'approved',
      str_starts_with($event, 'PAYMENT.CAPTURE.COMPLETED') => 'succeeded',
      str_starts_with($event, 'PAYMENT.CAPTURE.DENIED') => 'failed',
      str_starts_with($event, 'PAYMENT.CAPTURE.REFUNDED') => 'refunded',
      str_starts_with($event, 'CHECKOUT.ORDER.CANCELLED') => 'cancelled',
      str_starts_with($event, 'BILLING.SUBSCRIPTION') => 'subscription',
      default => 'unknown',
    };

    $normalized = [
      'event' => 'payment.'.$status,
      'provider_payment_id' => $orderId,
      'status' => $status === 'succeeded' ? 'succeeded' : ($status === 'failed' ? 'failed' : $status),
      'payload' => $request->all(),
    ];
    if (str_starts_with($event, 'PAYMENT.CAPTURE')) {
      $normalized['amount'] = $resource['amount']['value'] ?? null;
      $normalized['currency'] = $resource['amount']['currency_code'] ?? null;
      $normalized['custom_id'] = $resource['custom_id'] ?? null;
      $normalized['capture_id'] = $resource['id'] ?? null;
    }

    return $normalized;
  }

  public function refund(DonationPayment $payment, ?float $amount = null): bool
  {
    // Full refund via PayPal Refunds API — stubbed (returns true when configured).
    [$clientId, $clientSecret] = $this->credentials($payment->donation?->country_id);

    return $clientId !== '' && $clientSecret !== '';
  }

  public function supportsRecurring(): bool
  {
    return true;
  }

  // ──────────────────────────────────────────────────
  //  Internal helpers
  // ──────────────────────────────────────────────────

  private function verifyPayPalWebhookSignature(Request $request): void
  {
    [$clientId, $clientSecret] = $this->credentials(null);

    if ($clientId === '' || $clientSecret === '') {
      // Fall back to shared-secret approach from parent.
      $this->assertWebhookAuthenticated($request);

      return;
    }

    $webhookId = $this->webhookId();
    if ($webhookId === '') {
      // If no webhook ID is configured, fall back to shared-secret.
      $this->assertWebhookAuthenticated($request);

      return;
    }

    $token = $this->getAccessToken($clientId, $clientSecret, null);

    $verifyPayload = [
      'auth_algo' => $request->header('PAYPAL-AUTH-ALGO', ''),
      'cert_url' => $request->header('PAYPAL-CERT-URL', ''),
      'transmission_id' => $request->header('PAYPAL-TRANSMISSION-ID', ''),
      'transmission_sig' => $request->header('PAYPAL-TRANSMISSION-SIG', ''),
      'transmission_time' => $request->header('PAYPAL-TRANSMISSION-TIME', ''),
      'webhook_id' => $webhookId,
      'webhook_event' => $request->all(),
    ];

    $response = Http::withToken($token)
      ->post($this->apiUrl(null).'/v1/notifications/verify-webhook-signature', $verifyPayload);

    $verificationStatus = $response->json('verification_status', 'FAILURE');

    if ($verificationStatus !== 'SUCCESS') {
      throw new ApiException(
        ApiErrorCode::Unauthorized,
        'PayPal webhook signature verification failed.',
        null,
        401,
      );
    }
  }

  /**
   * Get OAuth2 bearer token (cached for 50 minutes).
   */
  private function getAccessToken(string $clientId, string $clientSecret, ?int $countryId): string
  {
    $cacheKey = 'paypal_token_'.md5($clientId).'_'.($countryId ?? 'global');

    return Cache::remember($cacheKey, 3000, function () use ($clientId, $clientSecret, $countryId): string {
      $response = Http::withBasicAuth($clientId, $clientSecret)
        ->asForm()
        ->post($this->apiUrl($countryId).'/v1/oauth2/token', ['grant_type' => 'client_credentials']);

      if (! $response->successful()) {
        throw new ApiException(
          ApiErrorCode::UnprocessableEntity,
          'Could not authenticate with PayPal. Check configuration.',
          null,
          422,
        );
      }

      return (string) $response->json('access_token', '');
    });
  }

  /**
   * @return array{0: string, 1: string}
   */
  private function credentials(?int $countryId): array
  {
    // 1. DB config row (most specific).
    $query = PaymentProviderConfig::query()
      ->where('provider', 'paypal')
      ->where('is_enabled', true);

    if ($countryId !== null) {
      $query->where(function ($builder) use ($countryId): void {
        $builder->whereNull('country_id')->orWhere('country_id', $countryId);
      });
    }

    $config = $query->orderByRaw('country_id IS NULL ASC')->first();

    if ($config !== null && is_array($config->credentials)) {
      $clientId = (string) ($config->credentials['client_id'] ?? '');
      $clientSecret = (string) ($config->credentials['client_secret'] ?? '');
      if ($clientId !== '' && $clientSecret !== '') {
        return [$clientId, $clientSecret];
      }
    }

    // 2. Env vars.
    return [
      (string) env('DONATIONS_PAYPAL_CLIENT_ID', env('PAYPAL_CLIENT_ID', '')),
      (string) env('DONATIONS_PAYPAL_CLIENT_SECRET', env('PAYPAL_CLIENT_SECRET', '')),
    ];
  }

  private function webhookId(): string
  {
    $config = PaymentProviderConfig::query()
      ->where('provider', 'paypal')
      ->where('is_enabled', true)
      ->first();

    if ($config !== null && is_array($config->credentials)) {
      $id = (string) ($config->credentials['webhook_id'] ?? '');
      if ($id !== '') {
        return $id;
      }
    }

    return (string) env('DONATIONS_PAYPAL_WEBHOOK_ID', env('PAYPAL_WEBHOOK_ID', ''));
  }

  private function isLiveMode(?int $countryId): bool
  {
    $config = PaymentProviderConfig::query()
      ->where('provider', 'paypal')
      ->where('is_enabled', true)
      ->first();

    if ($config !== null) {
      return (bool) $config->is_live;
    }

    return strtolower((string) env('DONATIONS_PAYPAL_MODE', 'sandbox')) === 'live';
  }

  private function apiUrl(?int $countryId): string
  {
    return $this->isLiveMode($countryId) ? self::LIVE_URL : self::SANDBOX_URL;
  }
}
