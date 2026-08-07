<?php

declare(strict_types=1);

namespace Tests\Feature\Donations;

use App\Models\User;
use App\Modules\Cms\Models\CmsCountry;
use App\Modules\Donations\Enums\DonationStatus;
use App\Modules\Donations\Enums\PaymentMethod;
use App\Modules\Donations\Models\CountryPaymentMethod;
use App\Modules\Donations\Models\Donation;
use App\Modules\Donations\Models\DonationFund;
use Database\Seeders\CmsSeeder;
use Database\Seeders\DonationsSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SuperAdminSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class DonationManagementTest extends TestCase
{
  use RefreshDatabase;

  private User $admin;

  protected function setUp(): void
  {
    parent::setUp();
    Storage::fake('public');

    $this->seed([
      RoleSeeder::class,
      PermissionSeeder::class,
      RolePermissionSeeder::class,
      CmsSeeder::class,
      DonationsSeeder::class,
      SuperAdminSeeder::class,
    ]);

    $this->admin = User::query()->where('email', 'admin@marketplaceministers.org')->firstOrFail();
  }

  public function test_public_can_list_funds_and_country_methods(): void
  {
    $this->getJson('/api/v1/public/donations/funds')
      ->assertOk()
      ->assertJsonPath('data.funds.0.type', 'general');

    $this->getJson('/api/v1/public/donations/methods?country=nigeria')
      ->assertOk()
      ->assertJsonPath('data.country.slug', 'nigeria')
      ->assertJsonStructure(['data' => ['methods', 'bank_accounts']]);
  }

  public function test_public_can_checkout_bank_donation_and_admin_confirms_with_receipt(): void
  {
    $response = $this->postJson('/api/v1/public/donations/checkout', [
      'amount' => 100,
      'currency' => 'USD',
      'country' => 'nigeria',
      'payment_method' => 'bank_account',
      'fund_type' => 'mission',
      'frequency' => 'one_time',
      'is_anonymous' => false,
      'needs_tax_receipt' => true,
      'name' => 'Adaeze Okafor',
      'email' => 'adaeze@example.com',
      'notes' => 'For mission work',
    ])->assertCreated();

    $donationId = $response->json('data.donation.id');
    $this->assertSame('instructions', $response->json('data.checkout.type'));
    $this->assertNotEmpty($response->json('data.checkout.instructions.accounts'));

    $this->actingAs($this->admin)
      ->postJson("/api/v1/donations/{$donationId}/confirm")
      ->assertOk()
      ->assertJsonPath('data.donation.status', DonationStatus::Succeeded->value)
      ->assertJsonPath('data.donation.receipt.type', 'standard');

    $this->assertDatabaseHas('donation_receipts', [
      'type' => 'tax',
    ]);
  }

  public function test_online_checkout_creates_processing_donation_and_webhook_completes(): void
  {
    $created = $this->postJson('/api/v1/public/donations/checkout', [
      'amount' => 50,
      'currency' => 'USD',
      'country' => 'usa',
      'payment_method' => 'stripe',
      'fund_type' => 'scholarship',
      'frequency' => 'monthly',
      'is_anonymous' => true,
      'email' => 'anon@example.com',
    ])->assertCreated();

    $this->assertSame('redirect', $created->json('data.checkout.type'));
    $this->assertSame('Anonymous Donor', $created->json('data.donation.donor_name'));
    $intent = $created->json('data.checkout.provider_intent_id');

    $this->postJson('/api/v1/public/donations/webhooks/stripe', [
      'event' => 'payment.succeeded',
      'provider_payment_id' => $intent,
      'status' => 'succeeded',
    ])->assertOk()
      ->assertJsonPath('data.donation.status', 'succeeded');

    $this->assertDatabaseHas('donation_subscriptions', ['interval' => 'monthly', 'status' => 'active']);
  }

  public function test_admin_analytics_and_method_configuration(): void
  {
    $this->actingAs($this->admin)
      ->getJson('/api/v1/donations/analytics')
      ->assertOk()
      ->assertJsonStructure(['data' => ['analytics' => ['total_amount', 'total_count', 'by_method']]]);

    $country = CmsCountry::query()->where('slug', 'kenya')->firstOrFail();

    $this->actingAs($this->admin)
      ->putJson('/api/v1/donations/countries/kenya/methods', [
        'method' => PaymentMethod::Paystack->value,
        'is_enabled' => true,
        'label' => 'Paystack Kenya',
      ])
      ->assertOk();

    $this->assertDatabaseHas('country_payment_methods', [
      'country_id' => $country->id,
      'method' => 'paystack',
      'is_enabled' => 1,
    ]);

    $this->assertGreaterThan(0, DonationFund::query()->count());
    $this->assertGreaterThan(0, CountryPaymentMethod::query()->count());
  }
}
