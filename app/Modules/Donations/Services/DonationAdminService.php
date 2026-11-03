<?php

declare(strict_types=1);

namespace App\Modules\Donations\Services;

use App\Contracts\ServiceContract;
use App\Models\User;
use App\Modules\Cms\Models\CmsCountry;
use App\Modules\Donations\Enums\DonationStatus;
use App\Modules\Donations\Enums\PaymentMethod;
use App\Modules\Donations\Models\CountryPaymentMethod;
use App\Modules\Donations\Models\Donation;
use App\Modules\Donations\Models\DonationBankAccount;
use App\Modules\Donations\Models\DonationFund;
use App\Modules\Donations\Models\DonationReceipt;
use App\Modules\Donations\Models\DonationSubscription;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class DonationAdminService implements ServiceContract
{
  public function __construct(
    private readonly DonationAuditService $auditService,
    private readonly DonationCheckoutService $checkoutService,
  ) {}

  public function paginateDonations(array $filters = []): LengthAwarePaginator
  {
    $query = Donation::query()->with(['fund', 'country', 'receipt'])->latest();

    if (! empty($filters['status'])) {
      $query->where('status', $filters['status']);
    }
    if (! empty($filters['payment_method'])) {
      $query->where('payment_method', $filters['payment_method']);
    }
    if (! empty($filters['payment_purpose']) && in_array($filters['payment_purpose'], Donation::purposes(), true)) {
      $query->where('payment_purpose', $filters['payment_purpose']);
    }
    if (! empty($filters['date_from'])) {
      $query->where('created_at', '>=', \Illuminate\Support\Carbon::parse((string) $filters['date_from'])->startOfDay());
    }
    if (! empty($filters['date_to'])) {
      $query->where('created_at', '<=', \Illuminate\Support\Carbon::parse((string) $filters['date_to'])->endOfDay());
    }
    if (! empty($filters['fund_id'])) {
      $fundId = DonationFund::query()->where('uuid', $filters['fund_id'])->value('id');
      $query->where('fund_id', $fundId);
    }
    if (! empty($filters['country'])) {
      $countryId = CmsCountry::query()->where('slug', $filters['country'])->orWhere('uuid', $filters['country'])->value('id');
      $query->where('country_id', $countryId);
    }
    if (! empty($filters['search'])) {
      $search = (string) $filters['search'];
      $query->where(function ($builder) use ($search): void {
        $builder->where('reference', 'like', "%{$search}%")
          ->orWhere('donor_name', 'like', "%{$search}%")
          ->orWhere('donor_email', 'like', "%{$search}%");
      });
    }

    return $query->paginate(min(max((int) ($filters['per_page'] ?? 25), 1), 100));
  }

  public function confirmOffline(Donation $donation, User $actor): Donation
  {
    $old = $donation->only(['status']);
    $updated = $this->checkoutService->confirmSucceeded($donation, $actor);
    $this->auditService->record('offline_confirmed', 'donation', $donation->id, $actor, $old, $updated->only(['status', 'paid_at']));

    return $updated;
  }

  /**
   * @return Collection<int, DonationFund>
   */
  public function funds(): Collection
  {
    return DonationFund::query()->orderBy('sort_order')->get();
  }

  public function upsertFund(array $data, ?DonationFund $fund = null): DonationFund
  {
    if ($fund === null) {
      return DonationFund::query()->create([
        'name' => $data['name'],
        'slug' => $data['slug'] ?? Str::slug($data['name']),
        'type' => $data['type'],
        'description' => $data['description'] ?? null,
        'is_active' => $data['is_active'] ?? true,
        'sort_order' => $data['sort_order'] ?? 0,
      ]);
    }

    $fund->fill([
      'name' => $data['name'] ?? $fund->name,
      'slug' => $data['slug'] ?? $fund->slug,
      'type' => $data['type'] ?? $fund->type,
      'description' => array_key_exists('description', $data) ? $data['description'] : $fund->description,
      'is_active' => array_key_exists('is_active', $data) ? (bool) $data['is_active'] : $fund->is_active,
      'sort_order' => $data['sort_order'] ?? $fund->sort_order,
    ])->save();

    return $fund->fresh();
  }

  /**
   * @return Collection<int, CountryPaymentMethod>
   */
  public function methodsForCountry(CmsCountry $country): Collection
  {
    return CountryPaymentMethod::query()
      ->where('country_id', $country->id)
      ->orderBy('sort_order')
      ->get();
  }

  public function upsertCountryMethod(CmsCountry $country, array $data): CountryPaymentMethod
  {
    $method = CountryPaymentMethod::query()->updateOrCreate(
      [
        'country_id' => $country->id,
        'method' => $data['method'],
      ],
      [
        'provider_key' => $data['provider_key'] ?? $data['method'],
        'label' => $data['label'] ?? null,
        'config' => $data['config'] ?? null,
        'is_enabled' => $data['is_enabled'] ?? true,
        'sort_order' => $data['sort_order'] ?? 0,
      ],
    );

    $this->auditService->record('country_method_upserted', 'country_payment_method', $method->id, request()->user(), null, [
      'country' => $country->slug,
      'method' => $method->method->value,
      'is_enabled' => $method->is_enabled,
    ]);

    return $method;
  }

  public function upsertBankAccount(CmsCountry $country, array $data, ?DonationBankAccount $account = null): DonationBankAccount
  {
    $payload = [
      'country_id' => $country->id,
      'bank_name' => $data['bank_name'],
      'account_name' => $data['account_name'],
      'account_number' => $data['account_number'],
      'routing_number' => $data['routing_number'] ?? null,
      'swift_code' => $data['swift_code'] ?? null,
      'iban' => $data['iban'] ?? null,
      'currency' => $data['currency'] ?? 'USD',
      'instructions' => $data['instructions'] ?? null,
      'is_active' => $data['is_active'] ?? true,
      'sort_order' => $data['sort_order'] ?? 0,
    ];

    if ($account === null) {
      return DonationBankAccount::query()->create($payload);
    }

    $account->fill($payload)->save();

    return $account->fresh();
  }

  /**
   * @return array<string, mixed>
   */
  /**
   * Donation totals cover payment_purpose=donation only; school enrollment and other module payments
   * that share the ledger are reported separately under by_purpose.
   *
   * @return array<string, mixed>
   */
  public function analytics(): array
  {
    $donations = fn () => Donation::query()->where('payment_purpose', Donation::PURPOSE_DONATION);
    $succeeded = $donations()->where('status', DonationStatus::Succeeded);

    return [
      'scope' => Donation::PURPOSE_DONATION,
      'total_amount' => (float) (clone $succeeded)->sum('amount'),
      'total_count' => (clone $succeeded)->count(),
      'pending_count' => $donations()->where('status', DonationStatus::Pending)->count(),
      'processing_count' => $donations()->where('status', DonationStatus::Processing)->count(),
      'recurring_active' => DonationSubscription::query()->where('status', 'active')->count(),
      'anonymous_count' => (clone $succeeded)->where('is_anonymous', true)->count(),
      'by_method' => $donations()
        ->where('status', DonationStatus::Succeeded)
        ->select('payment_method', DB::raw('COUNT(*) as count'), DB::raw('SUM(amount) as amount'))
        ->groupBy('payment_method')
        ->get(),
      'by_fund' => $donations()
        ->where('status', DonationStatus::Succeeded)
        ->select('fund_id', DB::raw('COUNT(*) as count'), DB::raw('SUM(amount) as amount'))
        ->groupBy('fund_id')
        ->with('fund')
        ->get(),
      'by_country' => $donations()
        ->where('status', DonationStatus::Succeeded)
        ->select('country_id', DB::raw('COUNT(*) as count'), DB::raw('SUM(amount) as amount'))
        ->groupBy('country_id')
        ->with('country')
        ->get(),
      'by_purpose' => Donation::query()
        ->where('status', DonationStatus::Succeeded)
        ->select('payment_purpose', 'currency', DB::raw('COUNT(*) as count'), DB::raw('SUM(amount) as amount'))
        ->groupBy('payment_purpose', 'currency')
        ->orderBy('payment_purpose')
        ->get()
        ->map(fn ($row) => [
          'payment_purpose' => $row->payment_purpose,
          'currency' => $row->currency,
          'count' => (int) $row->count,
          'amount' => (float) $row->amount,
        ])
        ->values(),
      'receipts_issued' => DonationReceipt::query()->count(),
    ];
  }
}
