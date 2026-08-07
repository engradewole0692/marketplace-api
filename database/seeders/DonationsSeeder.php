<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Cms\Models\CmsCountry;
use App\Modules\Donations\Enums\DonationType;
use App\Modules\Donations\Enums\PaymentMethod;
use App\Modules\Donations\Models\CountryPaymentMethod;
use App\Modules\Donations\Models\DonationBankAccount;
use App\Modules\Donations\Models\DonationFund;
use Illuminate\Database\Seeder;

final class DonationsSeeder extends Seeder
{
  public function run(): void
  {
    $funds = [
      ['slug' => 'general', 'name' => 'General Support', 'type' => DonationType::General, 'sort_order' => 1],
      ['slug' => 'mission', 'name' => 'Mission', 'type' => DonationType::Mission, 'sort_order' => 2],
      ['slug' => 'projects', 'name' => 'Projects', 'type' => DonationType::Projects, 'sort_order' => 3],
      ['slug' => 'events', 'name' => 'Events', 'type' => DonationType::Events, 'sort_order' => 4],
      ['slug' => 'building', 'name' => 'Building', 'type' => DonationType::Building, 'sort_order' => 5],
      ['slug' => 'scholarship', 'name' => 'Scholarship', 'type' => DonationType::Scholarship, 'sort_order' => 6],
    ];

    foreach ($funds as $fund) {
      DonationFund::query()->updateOrCreate(
        ['slug' => $fund['slug']],
        [
          'name' => $fund['name'],
          'type' => $fund['type'],
          'description' => $fund['name'].' giving fund',
          'is_active' => true,
          'sort_order' => $fund['sort_order'],
        ],
      );
    }

    $defaultMethods = [
      PaymentMethod::BankAccount,
      PaymentMethod::Card,
      PaymentMethod::Flutterwave,
      PaymentMethod::Paystack,
      PaymentMethod::Stripe,
      PaymentMethod::PayPal,
      PaymentMethod::Offline,
      PaymentMethod::Wire,
      PaymentMethod::Crypto,
    ];

    $countryDefaults = [
      'nigeria' => [PaymentMethod::BankAccount, PaymentMethod::Paystack, PaymentMethod::Flutterwave, PaymentMethod::Offline, PaymentMethod::Wire],
      'ghana' => [PaymentMethod::BankAccount, PaymentMethod::Paystack, PaymentMethod::Flutterwave, PaymentMethod::Offline],
      'kenya' => [PaymentMethod::BankAccount, PaymentMethod::Flutterwave, PaymentMethod::Offline, PaymentMethod::Wire],
      'usa' => [PaymentMethod::Card, PaymentMethod::Stripe, PaymentMethod::PayPal, PaymentMethod::Wire, PaymentMethod::Offline],
      'south-africa' => [PaymentMethod::BankAccount, PaymentMethod::Flutterwave, PaymentMethod::Offline, PaymentMethod::Wire],
    ];

    foreach (CmsCountry::query()->get() as $index => $country) {
      $enabled = $countryDefaults[$country->slug] ?? [PaymentMethod::BankAccount, PaymentMethod::Offline, PaymentMethod::Wire, PaymentMethod::Card];

      foreach ($defaultMethods as $sort => $method) {
        CountryPaymentMethod::query()->updateOrCreate(
          [
            'country_id' => $country->id,
            'method' => $method->value,
          ],
          [
            'provider_key' => $method->value,
            'label' => str_replace('_', ' ', ucwords($method->value, '_')),
            'is_enabled' => in_array($method, $enabled, true) && $method !== PaymentMethod::Crypto,
            'sort_order' => $sort + 1,
            'config' => $method === PaymentMethod::Crypto ? ['future_ready' => true] : null,
          ],
        );
      }

      if (in_array($country->slug, ['nigeria', 'ghana', 'kenya', 'usa', 'south-africa'], true)) {
        DonationBankAccount::query()->updateOrCreate(
          [
            'country_id' => $country->id,
            'account_number' => 'MPM-'.$country->code.'-001',
          ],
          [
            'bank_name' => 'Marketplace Ministers '.$country->name.' Account',
            'account_name' => 'Marketplace Ministers',
            'routing_number' => null,
            'swift_code' => 'MPM'.strtoupper($country->code).'XXX',
            'iban' => null,
            'currency' => $country->slug === 'usa' ? 'USD' : 'USD',
            'instructions' => 'Use your donation reference in the transfer narration.',
            'is_active' => true,
            'sort_order' => 1,
          ],
        );
      }
    }
  }
}
