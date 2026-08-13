<?php

declare(strict_types=1);

namespace Tests\Feature\Events;

use App\Modules\Donations\Models\Donation;
use App\Modules\Events\Enums\EventStatus;
use App\Modules\Events\Enums\EventVisibility;
use App\Modules\Events\Enums\PaymentStatus;
use App\Modules\Events\Enums\RegistrationStatus;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Models\EventRegistration;
use App\Modules\Events\Models\EventRegistrationPayment;
use App\Modules\Events\Services\EventCommerceService;
use Database\Seeders\CmsSeeder;
use Database\Seeders\CommunicationSeeder;
use Database\Seeders\DonationsSeeder;
use Tests\Feature\Iam\IamTestCase;

final class EventCommerceTest extends IamTestCase
{
  protected function setUp(): void
  {
    parent::setUp();
    $this->seed([CmsSeeder::class, DonationsSeeder::class, CommunicationSeeder::class]);
  }

  public function test_paid_registration_checkout_offline_and_confirm(): void
  {
    $event = Event::query()->create([
      'title' => 'Paid Summit',
      'slug' => 'paid-summit-'.uniqid(),
      'starts_at' => now()->addWeeks(2),
      'visibility' => EventVisibility::Public,
      'status' => EventStatus::Published,
      'published_at' => now(),
      'is_paid' => true,
      'price' => 75.00,
      'currency' => 'USD',
    ]);

    $register = $this->postJson('/api/v1/public/events/registrations', [
      'event_id' => $event->uuid,
      'registrant' => [
        'name' => 'Paying Guest',
        'email' => 'paying-guest@example.com',
      ],
      'consent_accepted' => true,
    ])->assertCreated();

    $registrationUuid = $register->json('data.registration.id');

    $checkout = $this->postJson("/api/v1/public/events/registrations/{$registrationUuid}/checkout", [
      'payment_method' => 'offline',
      'country' => 'nigeria',
      'email' => 'paying-guest@example.com',
    ])->assertCreated()->json('data');

    $this->assertSame('instructions', $checkout['checkout']['type'] ?? null);
    $this->assertNotNull($checkout['donation_reference']);

    $registration = EventRegistration::query()->where('uuid', $registrationUuid)->firstOrFail();
    $payment = EventRegistrationPayment::query()->where('registration_id', $registration->id)->firstOrFail();
    $this->assertNotNull($payment->donation_id);

    $donation = Donation::query()->findOrFail($payment->donation_id);
    app(EventCommerceService::class)->activateFromDonation($donation, $this->admin);

    $payment->refresh();
    $registration->refresh();

    $this->assertSame(PaymentStatus::Paid, $payment->status);
    $this->assertSame(RegistrationStatus::Approved, $registration->status);
  }
}
