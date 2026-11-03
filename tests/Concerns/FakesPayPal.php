<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Modules\Donations\Models\PaymentProviderConfig;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Sandbox-style PayPal Orders v2 fixture. Nothing leaves the test process.
 *
 * Orders are recorded with the amount/currency/custom_id sent at creation, and captures echo them back
 * unless a test overrides the capture result to simulate tampering, pending or declined payments.
 */
trait FakesPayPal
{
  /** @var array<string, array{amount: string, currency: string, custom_id: string, reference_id: string, description: string, return_url: string}> */
  protected array $paypalOrders = [];

  /** @var array<string, string|null> capture status by order id ("COMPLETED", "PENDING", "DECLINED") */
  protected array $paypalCaptureStatus = [];

  /** @var array<string, array{amount?: string, currency?: string, custom_id?: string}> */
  protected array $paypalCaptureOverrides = [];

  protected int $paypalCaptureCalls = 0;

  protected function fakePayPal(): void
  {
    PaymentProviderConfig::query()->updateOrCreate(
      ['provider' => 'paypal', 'country_id' => null],
      [
        'uuid' => (string) Str::uuid(),
        'credentials' => ['client_id' => 'sandbox-client', 'client_secret' => 'sandbox-secret'],
        'is_live' => false,
        'is_enabled' => true,
      ],
    );

    Http::fake(function (HttpRequest $request) {
      $url = $request->url();
      if (str_contains($url, 'api-m.paypal.com')) {
        return Http::response(['message' => 'live PayPal must never be called in tests'], 500);
      }
      if (str_ends_with($url, '/v1/oauth2/token')) {
        return Http::response(['access_token' => 'sandbox-token', 'expires_in' => 3600]);
      }
      if (preg_match('#/v2/checkout/orders/([^/]+)/capture$#', $url, $m) === 1) {
        $this->paypalCaptureCalls++;

        return Http::response($this->paypalOrderBody($m[1], true), 201);
      }
      if (preg_match('#/v2/checkout/orders/([^/]+)$#', $url, $m) === 1 && $request->method() === 'GET') {
        return Http::response($this->paypalOrderBody($m[1], true));
      }
      if (str_ends_with($url, '/v2/checkout/orders')) {
        $unit = $request->data()['purchase_units'][0] ?? [];
        $id = 'SANDBOX-'.Str::upper(Str::random(10));
        $this->paypalOrders[$id] = [
          'amount' => (string) ($unit['amount']['value'] ?? ''),
          'currency' => (string) ($unit['amount']['currency_code'] ?? ''),
          'custom_id' => (string) ($unit['custom_id'] ?? ''),
          'reference_id' => (string) ($unit['reference_id'] ?? ''),
          'description' => (string) ($unit['description'] ?? ''),
          'return_url' => (string) ($request->data()['application_context']['return_url'] ?? ''),
        ];

        return Http::response([
          'id' => $id,
          'status' => 'CREATED',
          'links' => [['rel' => 'approve', 'href' => 'https://www.sandbox.paypal.com/checkoutnow?token='.$id]],
        ], 201);
      }

      return Http::response(['message' => 'unexpected PayPal call '.$url], 404);
    });
  }

  /**
   * @return array<string, mixed>
   */
  private function paypalOrderBody(string $orderId, bool $captured): array
  {
    $order = $this->paypalOrders[$orderId] ?? ['amount' => '0.00', 'currency' => 'USD', 'custom_id' => '', 'reference_id' => ''];
    $override = $this->paypalCaptureOverrides[$orderId] ?? [];
    $status = $this->paypalCaptureStatus[$orderId] ?? 'COMPLETED';

    return [
      'id' => $orderId,
      'status' => $status === 'COMPLETED' ? 'COMPLETED' : 'APPROVED',
      'purchase_units' => [[
        'reference_id' => $order['reference_id'],
        'custom_id' => $override['custom_id'] ?? $order['custom_id'],
        'amount' => ['value' => $order['amount'], 'currency_code' => $order['currency']],
        'payments' => $captured ? ['captures' => [[
          'id' => 'CAPTURE-'.$orderId,
          'status' => $status,
          'custom_id' => $override['custom_id'] ?? $order['custom_id'],
          'amount' => [
            'value' => $override['amount'] ?? $order['amount'],
            'currency_code' => $override['currency'] ?? $order['currency'],
          ],
        ]]] : [],
      ]],
    ];
  }

  /** The order id PayPal would put in ?token= on the return URL. */
  protected function paypalTokenFor(string $redirectUrl): string
  {
    parse_str((string) parse_url($redirectUrl, PHP_URL_QUERY), $query);

    return (string) ($query['token'] ?? '');
  }
}
