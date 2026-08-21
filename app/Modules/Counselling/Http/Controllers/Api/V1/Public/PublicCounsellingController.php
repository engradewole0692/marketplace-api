<?php

declare(strict_types=1);

namespace App\Modules\Counselling\Http\Controllers\Api\V1\Public;

use App\Http\Controllers\Api\V1\ApiController;
use App\Modules\Counselling\Enums\ServiceFormat;
use App\Modules\Counselling\Http\Resources\CounsellingCaseResource;
use App\Modules\Counselling\Http\Resources\CounsellingCategoryResource;
use App\Modules\Counselling\Http\Resources\CounsellingServiceResource;
use App\Modules\Counselling\Models\CounsellingCase;
use App\Modules\Counselling\Models\CounsellingCategory;
use App\Modules\Counselling\Models\CounsellingService;
use App\Modules\Counselling\Services\CounsellingCaseService;
use App\Modules\Counselling\Services\CounsellingCatalogService;
use App\Support\Api\PaginatedResponseBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class PublicCounsellingController extends ApiController
{
  public function categories(CounsellingCatalogService $catalog): JsonResponse
  {
    $paginator = $catalog->paginateCategories([
      'is_visible' => true,
      'per_page' => 100,
    ]);

    return $this->responder->success(
      data: PaginatedResponseBuilder::fromPaginator($paginator, CounsellingCategoryResource::class),
      message: 'Counselling categories retrieved.',
    );
  }

  public function services(Request $request, CounsellingCatalogService $catalog): JsonResponse
  {
    $paginator = $catalog->paginateServices([
      ...$request->query(),
      'is_visible' => true,
      'status' => 'published',
    ]);

    return $this->responder->success(
      data: PaginatedResponseBuilder::fromPaginator($paginator, CounsellingServiceResource::class),
      message: 'Counselling services retrieved.',
    );
  }

  public function showService(string $slug): JsonResponse
  {
    $service = CounsellingService::query()
      ->with(['category', 'bannerMedia'])
      ->where('slug', $slug)
      ->where('is_visible', true)
      ->where('status', 'published')
      ->first();

    if ($service === null) {
      abort(404, 'Counselling service not found.');
    }

    return $this->responder->success(
      data: ['service' => new CounsellingServiceResource($service)],
      message: 'Counselling service retrieved.',
    );
  }

  public function request(Request $request, CounsellingCaseService $caseService): JsonResponse
  {
    $user = $request->user('sanctum');
    if ($user === null) {
      abort(401, 'Authentication required to request counselling.');
    }

    $this->authorize('create', CounsellingCase::class);

    $validated = $request->validate([
      'service_id' => ['nullable', 'uuid'],
      'category_id' => ['required', 'uuid'],
      'subject' => ['required', 'string', 'max:255'],
      'description' => ['required', 'string', 'max:5000'],
      'reason' => ['nullable', 'string', 'max:5000'],
      'preferred_language' => ['nullable', 'string', 'max:80'],
      'preferred_counsellor_gender' => ['nullable', 'string', 'max:50'],
      'preferred_format' => ['nullable', 'string', Rule::enum(ServiceFormat::class)],
      'preferred_at' => ['nullable', 'date'],
      'timezone' => ['nullable', 'string', 'max:64'],
      'urgency' => ['nullable', 'string', Rule::in(['emergency', 'normal'])],
      'who_is_this_for' => ['required', 'string', Rule::in(['myself', 'spouse_couple', 'child', 'someone_else'])],
      'terms_accepted' => ['accepted'],
      'client_name' => ['nullable', 'string', 'max:255'],
      'client_email' => ['nullable', 'email', 'max:255'],
      'client_phone' => ['nullable', 'string', 'max:50'],
      'client_country' => ['nullable', 'string', 'max:120'],
      'client_gender' => ['nullable', 'string', 'max:50'],
      'additional_notes' => ['nullable', 'string', 'max:5000'],
      'prayer_request' => ['nullable', 'string', 'max:5000'],
      'metadata' => ['nullable', 'array'],
      'attachment' => ['nullable', 'file', 'max:10240', 'mimes:pdf,doc,docx,jpg,jpeg,png,webp,zip'],
    ]);

    $category = CounsellingCategory::query()
      ->where('uuid', $validated['category_id'])
      ->where('is_visible', true)
      ->first();

    if ($category === null) {
      abort(404, 'Counselling category not available.');
    }

    $service = null;
    if (! empty($validated['service_id'])) {
      $service = CounsellingService::query()
        ->where('uuid', $validated['service_id'])
        ->where('is_visible', true)
        ->where('status', 'published')
        ->first();
    }

    if ($service === null) {
      $service = CounsellingService::query()
        ->where('category_id', $category->id)
        ->where('is_visible', true)
        ->where('status', 'published')
        ->orderBy('sort_order')
        ->first();
    }

    if ($service === null) {
      $service = CounsellingService::query()
        ->where('is_visible', true)
        ->where('status', 'published')
        ->orderBy('sort_order')
        ->first();
    }

    if ($service === null) {
      abort(422, 'No counselling service is available for requests yet.');
    }

    $payload = array_merge($validated, [
      'service_id' => $service->uuid,
      'category_id' => $category->uuid,
      'client_name' => $validated['client_name'] ?? $user->name,
      'client_email' => $validated['client_email'] ?? $user->email,
      'reason' => $validated['description'] ?? $validated['reason'] ?? null,
      'metadata' => array_merge($validated['metadata'] ?? [], [
        'subject' => $validated['subject'],
        'preferred_language' => $validated['preferred_language'] ?? null,
        'urgency' => $validated['urgency'] ?? 'normal',
        'additional_notes' => $validated['additional_notes'] ?? null,
        'terms_accepted' => true,
      ]),
    ]);

    $case = $caseService->createFromRequest($payload, $user);

    if ($request->hasFile('attachment')) {
      $file = $request->file('attachment');
      $path = $file->store('counselling/'.$case->uuid, 'local');
      \App\Modules\Counselling\Models\CounsellingDocument::query()->create([
        'case_id' => $case->id,
        'uploaded_by_user_id' => $user->id,
        'title' => $file->getClientOriginalName(),
        'disk_path' => $path,
        'mime_type' => $file->getClientMimeType(),
        'size_bytes' => $file->getSize(),
        'visibility' => 'client',
      ]);
    }

    return $this->responder->success(
      data: ['case' => new CounsellingCaseResource($case->load(['service', 'category', 'latestPayment', 'events']))],
      message: 'Counselling request submitted.',
      status: 201,
    );
  }
}
