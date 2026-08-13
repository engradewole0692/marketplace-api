<?php

declare(strict_types=1);

namespace App\Modules\Events\Http\Controllers\Api\V1\Public;

use App\Http\Controllers\Api\V1\ApiController;
use App\Modules\Events\Models\EventRegistration;
use App\Modules\Events\Services\EventCommerceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class PublicRegistrationCheckoutController extends ApiController
{
  public function checkout(Request $request, EventRegistration $registration, EventCommerceService $commerce): JsonResponse
  {
    $this->authorizeCheckout($request, $registration);

    $validated = $request->validate([
      'payment_method' => ['required', 'string', Rule::in([
        'paystack', 'flutterwave', 'stripe', 'card', 'offline', 'bank_account', 'wire', 'paypal', 'crypto',
      ])],
      'country' => ['nullable', 'string', 'max:80'],
      'country_id' => ['nullable', 'uuid'],
      'country_slug' => ['nullable', 'string', 'max:80'],
      'phone' => ['nullable', 'string', 'max:40'],
      'email' => ['nullable', 'email', 'max:255'],
    ]);

    if (empty($validated['country']) && empty($validated['country_id']) && empty($validated['country_slug'])) {
      $validated['country'] = 'nigeria';
    }

    $result = $commerce->checkout($registration, $validated, $request, $request->user());

    return $this->responder->success(
      data: [
        'payment' => $commerce->paymentPayload($result['payment']),
        'checkout' => $result['checkout'],
        'donation_reference' => $result['donation']->reference ?? null,
      ],
      message: 'Checkout started. Complete payment to confirm your registration.',
      status: 201,
    );
  }

  private function authorizeCheckout(Request $request, EventRegistration $registration): void
  {
    $user = $request->user();

    if ($user !== null) {
      if ($user->can('registrations.manage') || $user->can('event_payments.manage')) {
        return;
      }

      $user->loadMissing('member');
      if ($registration->member_id !== null && $user->member?->id === $registration->member_id) {
        return;
      }

      if ($registration->member_id === null && $request->filled('email')) {
        $provided = strtolower((string) $request->input('email'));
        $expected = strtolower((string) ($registration->contactEmail() ?? ''));
        if ($provided !== '' && $provided === $expected) {
          return;
        }
      }

      abort(403, 'You are not authorized to pay for this registration.');
    }

    $provided = strtolower((string) $request->input('email', ''));
    $expected = strtolower((string) ($registration->contactEmail() ?? ''));
    if ($provided === '' || $expected === '' || $provided !== $expected) {
      abort(403, 'Provide the registration email to start checkout.');
    }
  }
}
