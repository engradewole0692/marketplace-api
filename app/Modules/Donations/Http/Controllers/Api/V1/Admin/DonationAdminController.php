<?php

declare(strict_types=1);

namespace App\Modules\Donations\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\ApiController;
use App\Modules\Cms\Models\CmsCountry;
use App\Modules\Donations\Http\Resources\DonationResource;
use App\Modules\Donations\Models\Donation;
use App\Modules\Donations\Models\DonationAuditLog;
use App\Modules\Donations\Models\DonationBankAccount;
use App\Modules\Donations\Models\DonationFund;
use App\Modules\Donations\Services\DonationAdminService;
use App\Support\Api\PaginatedResponseBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class DonationAdminController extends ApiController
{
  public function index(Request $request, DonationAdminService $service): JsonResponse
  {
    $this->authorize('viewAny', Donation::class);

    return $this->responder->success(
      data: PaginatedResponseBuilder::fromPaginator($service->paginateDonations($request->query()), DonationResource::class),
      message: 'Donations retrieved.',
    );
  }

  public function show(Donation $donation): JsonResponse
  {
    $this->authorize('view', $donation);

    return $this->responder->success(
      data: ['donation' => new DonationResource($donation->load(['fund', 'country', 'receipt', 'payments']))],
      message: 'Donation retrieved.',
    );
  }

  public function confirm(Donation $donation, DonationAdminService $service, Request $request): JsonResponse
  {
    $this->authorize('update', $donation);
    $donation = $service->confirmOffline($donation, $request->user());

    return $this->responder->success(
      data: ['donation' => new DonationResource($donation)],
      message: 'Donation confirmed.',
    );
  }

  public function analytics(DonationAdminService $service): JsonResponse
  {
    $this->authorize('viewAny', Donation::class);

    return $this->responder->success(
      data: ['analytics' => $service->analytics()],
      message: 'Donation analytics retrieved.',
    );
  }

  public function funds(DonationAdminService $service): JsonResponse
  {
    $this->authorize('viewAny', Donation::class);

    return $this->responder->success(
      data: ['funds' => $service->funds()],
      message: 'Donation funds retrieved.',
    );
  }

  public function storeFund(Request $request, DonationAdminService $service): JsonResponse
  {
    $this->authorize('create', Donation::class);
    $validated = $request->validate([
      'name' => ['required', 'string', 'max:255'],
      'slug' => ['nullable', 'string', 'max:255'],
      'type' => ['required', Rule::in(['general', 'mission', 'projects', 'events', 'building', 'scholarship'])],
      'description' => ['nullable', 'string'],
      'is_active' => ['sometimes', 'boolean'],
      'sort_order' => ['sometimes', 'integer', 'min:0'],
    ]);

    $fund = $service->upsertFund($validated);

    return $this->responder->success(data: ['fund' => $fund], message: 'Fund created.', status: 201);
  }

  public function updateFund(Request $request, DonationFund $fund, DonationAdminService $service): JsonResponse
  {
    $this->authorize('update', Donation::class);
    $validated = $request->validate([
      'name' => ['sometimes', 'string', 'max:255'],
      'slug' => ['nullable', 'string', 'max:255'],
      'type' => ['sometimes', Rule::in(['general', 'mission', 'projects', 'events', 'building', 'scholarship'])],
      'description' => ['nullable', 'string'],
      'is_active' => ['sometimes', 'boolean'],
      'sort_order' => ['sometimes', 'integer', 'min:0'],
    ]);

        $fund = $service->upsertFund($validated, $fund);

    return $this->responder->success(data: ['fund' => $fund], message: 'Fund updated.');
  }

  public function countryMethods(string $country, DonationAdminService $service): JsonResponse
  {
    $this->authorize('viewAny', Donation::class);
    $record = CmsCountry::query()->where('slug', $country)->orWhere('uuid', $country)->firstOrFail();

    return $this->responder->success(
      data: ['methods' => $service->methodsForCountry($record)],
      message: 'Country payment methods retrieved.',
    );
  }

  public function upsertCountryMethod(Request $request, string $country, DonationAdminService $service): JsonResponse
  {
    $this->authorize('update', Donation::class);
    $record = CmsCountry::query()->where('slug', $country)->orWhere('uuid', $country)->firstOrFail();
    $validated = $request->validate([
      'method' => ['required', Rule::in(['bank_account', 'card', 'flutterwave', 'paystack', 'stripe', 'paypal', 'offline', 'wire', 'crypto'])],
      'provider_key' => ['nullable', 'string', 'max:50'],
      'label' => ['nullable', 'string', 'max:120'],
      'config' => ['nullable', 'array'],
      'is_enabled' => ['sometimes', 'boolean'],
      'sort_order' => ['sometimes', 'integer', 'min:0'],
    ]);

    $method = $service->upsertCountryMethod($record, $validated);

    return $this->responder->success(data: ['method' => $method], message: 'Country payment method saved.');
  }

  public function storeBankAccount(Request $request, string $country, DonationAdminService $service): JsonResponse
  {
    $this->authorize('update', Donation::class);
    $record = CmsCountry::query()->where('slug', $country)->orWhere('uuid', $country)->firstOrFail();
    $validated = $request->validate([
      'bank_name' => ['required', 'string', 'max:255'],
      'account_name' => ['required', 'string', 'max:255'],
      'account_number' => ['required', 'string', 'max:120'],
      'routing_number' => ['nullable', 'string', 'max:120'],
      'swift_code' => ['nullable', 'string', 'max:50'],
      'iban' => ['nullable', 'string', 'max:80'],
      'currency' => ['nullable', 'string', 'max:10'],
      'instructions' => ['nullable', 'string'],
      'is_active' => ['sometimes', 'boolean'],
      'sort_order' => ['sometimes', 'integer', 'min:0'],
    ]);

    $account = $service->upsertBankAccount($record, $validated);

    return $this->responder->success(data: ['bank_account' => $account], message: 'Bank account saved.', status: 201);
  }

  public function auditLogs(Request $request): JsonResponse
  {
    $this->authorize('viewAny', Donation::class);

    $logs = DonationAuditLog::query()->with('actor')->latest()->paginate(min((int) $request->query('per_page', 25), 100));

    return $this->responder->success(data: PaginatedResponseBuilder::fromPaginator($logs), message: 'Donation audit logs retrieved.');
  }
}
