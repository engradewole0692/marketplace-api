<?php

declare(strict_types=1);

namespace App\Modules\Counselling\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\ApiController;
use App\Models\User;
use App\Modules\Counselling\Enums\CaseStatus;
use App\Modules\Counselling\Enums\NoteVisibility;
use App\Modules\Counselling\Enums\ServiceFormat;
use App\Modules\Counselling\Http\Resources\CounsellingCaseResource;
use App\Modules\Counselling\Http\Resources\CounsellingCategoryResource;
use App\Modules\Counselling\Http\Resources\CounsellingServiceResource;
use App\Modules\Counselling\Http\Resources\CounsellorResource;
use App\Modules\Counselling\Models\CounsellingAppointment;
use App\Modules\Counselling\Models\CounsellingCase;
use App\Modules\Counselling\Models\CounsellingCategory;
use App\Modules\Counselling\Models\CounsellingDocument;
use App\Modules\Counselling\Models\CounsellingNote;
use App\Modules\Counselling\Models\CounsellingPayment;
use App\Modules\Counselling\Models\CounsellingService;
use App\Modules\Counselling\Models\Counsellor;
use App\Modules\Counselling\Services\CounsellingAppointmentService;
use App\Modules\Counselling\Services\CounsellingCaseService;
use App\Modules\Counselling\Services\CounsellingCatalogService;
use App\Modules\Counselling\Services\CounsellingMessagingService;
use App\Modules\Counselling\Services\CounsellingPaymentService;
use App\Modules\Counselling\Services\CounsellingReportingService;
use App\Modules\Counselling\Services\CounsellorAssignmentEngine;
use App\Support\Api\PaginatedResponseBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

final class CounsellingAdminController extends ApiController
{
  public function dashboard(Request $request, CounsellingReportingService $reporting): JsonResponse
  {
    $this->authorizeManageOrView($request);

    return $this->responder->success(
      data: $reporting->dashboard($request->query()),
      message: 'Counselling dashboard retrieved.',
    );
  }

  public function reports(Request $request, CounsellingReportingService $reporting): JsonResponse
  {
    $this->authorizeManageOrView($request);

    $type = (string) $request->query('type', 'dashboard');
    $data = match ($type) {
      'dashboard', 'summary' => $reporting->dashboard($request->query()),
      default => $reporting->dashboard($request->query()),
    };

    return $this->responder->success(
      data: ['type' => $type, 'report' => $data],
      message: 'Counselling report retrieved.',
    );
  }

  public function indexCategories(Request $request, CounsellingCatalogService $catalog): JsonResponse
  {
    $this->authorize('permission', 'counselling.manage');

    return $this->responder->success(
      data: PaginatedResponseBuilder::fromPaginator(
        $catalog->paginateCategories($request->query()),
        CounsellingCategoryResource::class,
      ),
      message: 'Counselling categories retrieved.',
    );
  }

  public function storeCategory(Request $request, CounsellingCatalogService $catalog): JsonResponse
  {
    $this->authorize('permission', 'counselling.manage');

    $validated = $request->validate([
      'name' => ['required', 'string', 'max:255'],
      'slug' => ['nullable', 'string', 'max:255'],
      'description' => ['nullable', 'string'],
      'icon' => ['nullable', 'string', 'max:80'],
      'sort_order' => ['nullable', 'integer', 'min:0'],
      'is_visible' => ['sometimes', 'boolean'],
      'status' => ['nullable', 'string', Rule::in(['active', 'inactive'])],
      'seo_title' => ['nullable', 'string', 'max:255'],
      'seo_description' => ['nullable', 'string'],
    ]);

    $category = $catalog->createCategory($validated);

    return $this->responder->success(
      data: ['category' => new CounsellingCategoryResource($category)],
      message: 'Counselling category created.',
      status: 201,
    );
  }

  public function updateCategory(
    Request $request,
    CounsellingCategory $category,
    CounsellingCatalogService $catalog,
  ): JsonResponse {
    $this->authorize('permission', 'counselling.manage');

    $validated = $request->validate([
      'name' => ['sometimes', 'string', 'max:255'],
      'slug' => ['sometimes', 'nullable', 'string', 'max:255'],
      'description' => ['sometimes', 'nullable', 'string'],
      'icon' => ['sometimes', 'nullable', 'string', 'max:80'],
      'sort_order' => ['sometimes', 'integer', 'min:0'],
      'is_visible' => ['sometimes', 'boolean'],
      'status' => ['sometimes', 'string', Rule::in(['active', 'inactive'])],
      'seo_title' => ['sometimes', 'nullable', 'string', 'max:255'],
      'seo_description' => ['sometimes', 'nullable', 'string'],
    ]);

    $category = $catalog->updateCategory($category, $validated);

    return $this->responder->success(
      data: ['category' => new CounsellingCategoryResource($category)],
      message: 'Counselling category updated.',
    );
  }

  public function destroyCategory(CounsellingCategory $category, CounsellingCatalogService $catalog): JsonResponse
  {
    $this->authorize('permission', 'counselling.manage');
    $catalog->deleteCategory($category);

    return $this->responder->success(message: 'Counselling category deleted.');
  }

  public function indexServices(Request $request, CounsellingCatalogService $catalog): JsonResponse
  {
    $this->authorize('permission', 'counselling.manage');

    return $this->responder->success(
      data: PaginatedResponseBuilder::fromPaginator(
        $catalog->paginateServices($request->query()),
        CounsellingServiceResource::class,
      ),
      message: 'Counselling services retrieved.',
    );
  }

  public function storeService(Request $request, CounsellingCatalogService $catalog): JsonResponse
  {
    $this->authorize('permission', 'counselling.manage');

    $validated = $request->validate([
      'title' => ['required', 'string', 'max:255'],
      'slug' => ['nullable', 'string', 'max:255'],
      'category_id' => ['nullable', 'uuid'],
      'description' => ['nullable', 'string'],
      'short_description' => ['nullable', 'string', 'max:500'],
      'icon' => ['nullable', 'string', 'max:80'],
      'banner_media_id' => ['nullable', 'uuid'],
      'duration_minutes' => ['nullable', 'integer', 'min:15', 'max:480'],
      'format' => ['nullable', 'string', Rule::enum(ServiceFormat::class)],
      'google_meet_link' => ['nullable', 'string', 'max:500'],
      'zoom_link' => ['nullable', 'string', 'max:500'],
      'teams_link' => ['nullable', 'string', 'max:500'],
      'office_address' => ['nullable', 'string', 'max:500'],
      'maximum_sessions' => ['nullable', 'integer', 'min:1', 'max:20'],
      'requires_approval' => ['sometimes', 'boolean'],
      'requires_payment' => ['sometimes', 'boolean'],
      'is_free' => ['sometimes', 'boolean'],
      'visitor_price' => ['nullable', 'numeric', 'min:0'],
      'member_price' => ['nullable', 'numeric', 'min:0'],
      'currency' => ['nullable', 'string', 'size:3'],
      'is_visible' => ['sometimes', 'boolean'],
      'is_featured' => ['sometimes', 'boolean'],
      'sort_order' => ['nullable', 'integer', 'min:0'],
      'seo_title' => ['nullable', 'string', 'max:255'],
      'seo_description' => ['nullable', 'string'],
      'status' => ['nullable', 'string', Rule::in(['draft', 'published', 'archived'])],
      'metadata' => ['nullable', 'array'],
    ]);

    $service = $catalog->createService($validated, $request->user());

    return $this->responder->success(
      data: ['service' => new CounsellingServiceResource($service->load(['category', 'bannerMedia']))],
      message: 'Counselling service created.',
      status: 201,
    );
  }

  public function updateService(
    Request $request,
    CounsellingService $service,
    CounsellingCatalogService $catalog,
  ): JsonResponse {
    $this->authorize('permission', 'counselling.manage');

    $validated = $request->validate([
      'title' => ['sometimes', 'string', 'max:255'],
      'slug' => ['sometimes', 'nullable', 'string', 'max:255'],
      'category_id' => ['sometimes', 'nullable', 'uuid'],
      'description' => ['sometimes', 'nullable', 'string'],
      'short_description' => ['sometimes', 'nullable', 'string', 'max:500'],
      'icon' => ['sometimes', 'nullable', 'string', 'max:80'],
      'banner_media_id' => ['sometimes', 'nullable', 'uuid'],
      'duration_minutes' => ['sometimes', 'integer', 'min:15', 'max:480'],
      'format' => ['sometimes', 'string', Rule::enum(ServiceFormat::class)],
      'google_meet_link' => ['sometimes', 'nullable', 'string', 'max:500'],
      'zoom_link' => ['sometimes', 'nullable', 'string', 'max:500'],
      'teams_link' => ['sometimes', 'nullable', 'string', 'max:500'],
      'office_address' => ['sometimes', 'nullable', 'string', 'max:500'],
      'maximum_sessions' => ['sometimes', 'integer', 'min:1', 'max:20'],
      'requires_approval' => ['sometimes', 'boolean'],
      'requires_payment' => ['sometimes', 'boolean'],
      'is_free' => ['sometimes', 'boolean'],
      'visitor_price' => ['sometimes', 'nullable', 'numeric', 'min:0'],
      'member_price' => ['sometimes', 'nullable', 'numeric', 'min:0'],
      'currency' => ['sometimes', 'nullable', 'string', 'size:3'],
      'is_visible' => ['sometimes', 'boolean'],
      'is_featured' => ['sometimes', 'boolean'],
      'sort_order' => ['sometimes', 'integer', 'min:0'],
      'seo_title' => ['sometimes', 'nullable', 'string', 'max:255'],
      'seo_description' => ['sometimes', 'nullable', 'string'],
      'status' => ['sometimes', 'string', Rule::in(['draft', 'published', 'archived'])],
      'metadata' => ['sometimes', 'nullable', 'array'],
    ]);

    $service = $catalog->updateService($service, $validated, $request->user());

    return $this->responder->success(
      data: ['service' => new CounsellingServiceResource($service)],
      message: 'Counselling service updated.',
    );
  }

  public function destroyService(CounsellingService $service, CounsellingCatalogService $catalog): JsonResponse
  {
    $this->authorize('permission', 'counselling.manage');
    $catalog->deleteService($service);

    return $this->responder->success(message: 'Counselling service deleted.');
  }

  public function indexCases(Request $request): JsonResponse
  {
    $this->authorize('viewAny', CounsellingCase::class);

    $query = CounsellingCase::query()
      ->with(['service', 'category', 'counsellor.user', 'latestPayment'])
      ->latest();

    if ($search = $request->query('q')) {
      $term = (string) $search;
      $query->where(function ($q) use ($term): void {
        $q->where('case_number', 'like', "%{$term}%")
          ->orWhere('client_name', 'like', "%{$term}%")
          ->orWhere('client_email', 'like', "%{$term}%")
          ->orWhere('client_phone', 'like', "%{$term}%");
      });
    }

    if ($status = $request->query('status')) {
      $query->where('status', (string) $status);
    }

    if ($counsellorUuid = $request->query('counsellor_id')) {
      $counsellorId = Counsellor::query()->where('uuid', $counsellorUuid)->value('id');
      if ($counsellorId) {
        $query->where('counsellor_id', $counsellorId);
      }
    }

    if ($clientType = $request->query('client_type')) {
      $query->where('client_type', (string) $clientType);
    }

    if ($email = $request->query('email')) {
      $query->where('client_email', (string) $email);
    }

    $perPage = min(100, max(1, (int) $request->query('per_page', 25)));

    return $this->responder->success(
      data: PaginatedResponseBuilder::fromPaginator(
        $query->paginate($perPage),
        CounsellingCaseResource::class,
      ),
      message: 'Counselling cases retrieved.',
    );
  }

  public function showCase(CounsellingCase $counsellingCase): JsonResponse
  {
    $this->authorize('view', $counsellingCase);

    $counsellingCase->load([
      'service.category',
      'category',
      'counsellor.user',
      'member',
      'user',
      'latestPayment',
      'nextAppointment',
      'appointments.counsellor.user',
      'payments',
      'events.actor',
    ]);

    return $this->responder->success(
      data: ['case' => new CounsellingCaseResource($counsellingCase)],
      message: 'Counselling case retrieved.',
    );
  }

  public function assignCase(
    Request $request,
    CounsellingCase $counsellingCase,
    CounsellingCaseService $caseService,
    CounsellorAssignmentEngine $assignmentEngine,
  ): JsonResponse {
    $this->authorize('update', $counsellingCase);

    $validated = $request->validate([
      'counsellor_id' => ['nullable', 'uuid'],
      'auto' => ['sometimes', 'boolean'],
    ]);

    if ($request->boolean('auto')) {
      $counsellor = $assignmentEngine->autoAssign($counsellingCase);
      if ($counsellor === null) {
        abort(422, 'No suitable counsellor available for auto-assignment.');
      }
      $counsellingCase = $caseService->assignCounsellor($counsellingCase, $counsellor, $request->user());
    } elseif (! empty($validated['counsellor_id'])) {
      $counsellor = Counsellor::query()->where('uuid', $validated['counsellor_id'])->firstOrFail();
      $counsellingCase = $caseService->assignCounsellor($counsellingCase, $counsellor, $request->user());
    } else {
      abort(422, 'Provide counsellor_id or set auto=true.');
    }

    return $this->responder->success(
      data: ['case' => new CounsellingCaseResource($counsellingCase->load(['service', 'counsellor.user']))],
      message: 'Counsellor assigned.',
    );
  }

  public function transitionCase(Request $request, CounsellingCase $counsellingCase, CounsellingCaseService $caseService): JsonResponse
  {
    $this->authorize('update', $counsellingCase);

    $validated = $request->validate([
      'status' => ['required', 'string', Rule::enum(CaseStatus::class)],
      'note' => ['nullable', 'string', 'max:2000'],
    ]);

    $counsellingCase = $caseService->transitionStatus(
      $counsellingCase,
      CaseStatus::from($validated['status']),
      $request->user(),
      $validated['note'] ?? null,
    );

    return $this->responder->success(
      data: ['case' => new CounsellingCaseResource($counsellingCase)],
      message: 'Case status updated.',
    );
  }

  public function cancelCase(Request $request, CounsellingCase $counsellingCase, CounsellingCaseService $caseService): JsonResponse
  {
    $this->authorize('update', $counsellingCase);

    $validated = $request->validate([
      'reason' => ['nullable', 'string', 'max:2000'],
    ]);

    $counsellingCase = $caseService->cancel($counsellingCase, $request->user(), $validated['reason'] ?? null);

    return $this->responder->success(
      data: ['case' => new CounsellingCaseResource($counsellingCase)],
      message: 'Case cancelled.',
    );
  }

  public function scheduleAppointment(
    Request $request,
    CounsellingCase $counsellingCase,
    CounsellingAppointmentService $appointments,
  ): JsonResponse {
    $this->authorize('update', $counsellingCase);

    $validated = $request->validate([
      'starts_at' => ['required', 'date'],
      'ends_at' => ['nullable', 'date', 'after:starts_at'],
      'format' => ['nullable', 'string', Rule::enum(ServiceFormat::class)],
      'meeting_link' => ['nullable', 'string', 'max:500'],
      'timezone' => ['nullable', 'string', 'max:64'],
    ]);

    $appointment = $appointments->schedule($counsellingCase, $validated, $request->user());

    return $this->responder->success(
      data: ['appointment' => $this->appointmentPayload($appointment)],
      message: 'Appointment scheduled.',
      status: 201,
    );
  }

  public function rescheduleAppointment(
    Request $request,
    CounsellingAppointment $appointment,
    CounsellingAppointmentService $appointments,
  ): JsonResponse {
    $appointment->loadMissing('case');
    $this->authorize('update', $appointment->case);

    $validated = $request->validate([
      'starts_at' => ['required', 'date'],
      'ends_at' => ['nullable', 'date', 'after:starts_at'],
    ]);

    $appointment = $appointments->reschedule($appointment, $validated, $request->user());

    return $this->responder->success(
      data: ['appointment' => $this->appointmentPayload($appointment)],
      message: 'Appointment rescheduled.',
    );
  }

  public function confirmAppointment(
    CounsellingAppointment $appointment,
    CounsellingAppointmentService $appointments,
    Request $request,
  ): JsonResponse {
    $appointment->loadMissing('case');
    $this->authorize('update', $appointment->case);

    $appointment = $appointments->confirm($appointment, $request->user());

    return $this->responder->success(
      data: ['appointment' => $this->appointmentPayload($appointment)],
      message: 'Appointment confirmed.',
    );
  }

  public function completeAppointment(
    CounsellingAppointment $appointment,
    CounsellingAppointmentService $appointments,
    Request $request,
  ): JsonResponse {
    $appointment->loadMissing('case');
    $this->authorize('update', $appointment->case);

    $appointment = $appointments->markCompleted($appointment, $request->user());

    return $this->responder->success(
      data: ['appointment' => $this->appointmentPayload($appointment)],
      message: 'Appointment marked completed.',
    );
  }

  public function missedAppointment(
    CounsellingAppointment $appointment,
    CounsellingAppointmentService $appointments,
    Request $request,
  ): JsonResponse {
    $appointment->loadMissing('case');
    $this->authorize('update', $appointment->case);

    $appointment = $appointments->markMissed($appointment, $request->user());

    return $this->responder->success(
      data: ['appointment' => $this->appointmentPayload($appointment)],
      message: 'Appointment marked missed.',
    );
  }

  public function markPaymentPaid(
    Request $request,
    CounsellingCase $counsellingCase,
    CounsellingPaymentService $payments,
  ): JsonResponse {
    $this->authorize('update', $counsellingCase);

    $validated = $request->validate([
      'payment_reference' => ['nullable', 'string', 'max:255'],
      'provider' => ['nullable', 'string', 'max:40'],
    ]);

    $payment = $counsellingCase->payments()->latest()->first();
    if ($payment === null) {
      abort(404, 'No payment record found for this case.');
    }

    $payment = $payments->markPaid($payment, $validated, $request->user());

    return $this->responder->success(
      data: [
        'payment' => [
          'id' => $payment->uuid,
          'status' => $payment->status instanceof \BackedEnum ? $payment->status->value : $payment->status,
          'amount' => (float) $payment->amount,
          'currency' => $payment->currency,
          'paid_at' => $payment->paid_at?->toIso8601String(),
        ],
      ],
      message: 'Payment marked as paid.',
    );
  }

  public function indexCounsellors(Request $request): JsonResponse
  {
    $this->authorize('permission', 'counselling.manage');

    $query = Counsellor::query()
      ->with(['user', 'photoMedia', 'availability'])
      ->orderBy('sort_order');

    if ($request->boolean('active_only', false)) {
      $query->where('is_active', true);
    }

    $perPage = min(100, max(1, (int) $request->query('per_page', 50)));

    return $this->responder->success(
      data: PaginatedResponseBuilder::fromPaginator(
        $query->paginate($perPage),
        CounsellorResource::class,
      ),
      message: 'Counsellors retrieved.',
    );
  }

  public function storeCounsellor(Request $request): JsonResponse
  {
    $this->authorize('permission', 'counselling.manage');

    $validated = $request->validate([
      'user_id' => ['required', 'uuid', 'exists:users,uuid'],
      'display_name' => ['required', 'string', 'max:255'],
      'slug' => ['nullable', 'string', 'max:255'],
      'biography' => ['nullable', 'string'],
      'specializations' => ['nullable', 'array'],
      'specializations.*' => ['string', 'max:120'],
      'languages' => ['nullable', 'array'],
      'languages.*' => ['string', 'max:80'],
      'google_meet_link' => ['nullable', 'string', 'max:500'],
      'zoom_link' => ['nullable', 'string', 'max:500'],
      'teams_link' => ['nullable', 'string', 'max:500'],
      'max_daily_sessions' => ['nullable', 'integer', 'min:1', 'max:20'],
      'is_active' => ['sometimes', 'boolean'],
      'sort_order' => ['nullable', 'integer', 'min:0'],
      'metadata' => ['nullable', 'array'],
      'availability' => ['nullable', 'array'],
      'availability.*.weekday' => ['required_with:availability', 'integer', 'min:0', 'max:6'],
      'availability.*.starts_at' => ['required_with:availability', 'string', 'max:20'],
      'availability.*.ends_at' => ['required_with:availability', 'string', 'max:20'],
      'availability.*.timezone' => ['nullable', 'string', 'max:64'],
      'availability.*.is_active' => ['sometimes', 'boolean'],
    ]);

    $userId = User::query()->where('uuid', $validated['user_id'])->value('id');
    $slug = $this->uniqueCounsellorSlug($validated['slug'] ?? Str::slug($validated['display_name']));

    $counsellor = Counsellor::query()->create([
      'user_id' => $userId,
      'display_name' => $validated['display_name'],
      'slug' => $slug,
      'biography' => $validated['biography'] ?? null,
      'specializations' => $validated['specializations'] ?? [],
      'languages' => $validated['languages'] ?? [],
      'google_meet_link' => $validated['google_meet_link'] ?? null,
      'zoom_link' => $validated['zoom_link'] ?? null,
      'teams_link' => $validated['teams_link'] ?? null,
      'max_daily_sessions' => (int) ($validated['max_daily_sessions'] ?? 6),
      'is_active' => (bool) ($validated['is_active'] ?? true),
      'sort_order' => (int) ($validated['sort_order'] ?? 0),
      'metadata' => $validated['metadata'] ?? null,
    ]);

    $this->syncAvailability($counsellor, $validated['availability'] ?? []);

    return $this->responder->success(
      data: ['counsellor' => new CounsellorResource($counsellor->load(['user', 'availability']))],
      message: 'Counsellor created.',
      status: 201,
    );
  }

  public function updateCounsellor(Request $request, Counsellor $counsellor): JsonResponse
  {
    $this->authorize('permission', 'counselling.manage');

    $validated = $request->validate([
      'user_id' => ['sometimes', 'uuid', 'exists:users,uuid'],
      'display_name' => ['sometimes', 'string', 'max:255'],
      'slug' => ['sometimes', 'nullable', 'string', 'max:255'],
      'biography' => ['sometimes', 'nullable', 'string'],
      'specializations' => ['sometimes', 'array'],
      'specializations.*' => ['string', 'max:120'],
      'languages' => ['sometimes', 'array'],
      'languages.*' => ['string', 'max:80'],
      'google_meet_link' => ['sometimes', 'nullable', 'string', 'max:500'],
      'zoom_link' => ['sometimes', 'nullable', 'string', 'max:500'],
      'teams_link' => ['sometimes', 'nullable', 'string', 'max:500'],
      'max_daily_sessions' => ['sometimes', 'integer', 'min:1', 'max:20'],
      'is_active' => ['sometimes', 'boolean'],
      'sort_order' => ['sometimes', 'integer', 'min:0'],
      'metadata' => ['sometimes', 'nullable', 'array'],
      'availability' => ['sometimes', 'array'],
      'availability.*.weekday' => ['required_with:availability', 'integer', 'min:0', 'max:6'],
      'availability.*.starts_at' => ['required_with:availability', 'string', 'max:20'],
      'availability.*.ends_at' => ['required_with:availability', 'string', 'max:20'],
      'availability.*.timezone' => ['nullable', 'string', 'max:64'],
      'availability.*.is_active' => ['sometimes', 'boolean'],
    ]);

    $payload = collect($validated)->except(['user_id', 'availability', 'slug'])->all();

    if (isset($validated['user_id'])) {
      $payload['user_id'] = User::query()->where('uuid', $validated['user_id'])->value('id');
    }

    if (array_key_exists('slug', $validated)) {
      $payload['slug'] = $this->uniqueCounsellorSlug((string) ($validated['slug'] ?: $counsellor->display_name), $counsellor->id);
    }

    $counsellor->fill($payload)->save();

    if (array_key_exists('availability', $validated)) {
      $this->syncAvailability($counsellor, $validated['availability'] ?? []);
    }

    return $this->responder->success(
      data: ['counsellor' => new CounsellorResource($counsellor->fresh(['user', 'availability']))],
      message: 'Counsellor updated.',
    );
  }

  public function storeNote(Request $request, CounsellingCase $counsellingCase): JsonResponse
  {
    $this->authorize('update', $counsellingCase);

    $validated = $request->validate([
      'body' => ['required', 'string', 'max:5000'],
      'visibility' => ['required', 'string', Rule::in(['counsellor', 'admin'])],
    ]);

    $note = CounsellingNote::query()->create([
      'case_id' => $counsellingCase->id,
      'author_user_id' => $request->user()->id,
      'visibility' => NoteVisibility::from($validated['visibility']),
      'body' => $validated['body'],
    ]);

    return $this->responder->success(
      data: ['note' => $this->notePayload($note->load('author'))],
      message: 'Note added.',
      status: 201,
    );
  }

  public function listMessages(
    Request $request,
    CounsellingCase $counsellingCase,
    CounsellingMessagingService $messaging,
  ): JsonResponse {
    $this->authorize('view', $counsellingCase);

    $paginator = $messaging->listForCase($counsellingCase, $request->user(), $request->query());

    return $this->responder->success(
      data: [
        'data' => collect($paginator->items())->map(fn ($message) => $this->messagePayload($message))->values(),
        'meta' => [
          'current_page' => $paginator->currentPage(),
          'last_page' => $paginator->lastPage(),
          'per_page' => $paginator->perPage(),
          'total' => $paginator->total(),
          'from' => $paginator->firstItem(),
          'to' => $paginator->lastItem(),
        ],
      ],
      message: 'Messages retrieved.',
    );
  }

  public function sendMessage(
    Request $request,
    CounsellingCase $counsellingCase,
    CounsellingMessagingService $messaging,
  ): JsonResponse {
    $this->authorize('update', $counsellingCase);

    $validated = $request->validate([
      'body' => ['required', 'string', 'max:5000'],
      'attachments' => ['nullable', 'array'],
    ]);

    $message = $messaging->sendMessage($counsellingCase, $request->user(), [
      ...$validated,
      'sender_role' => 'admin',
    ]);

    return $this->responder->success(
      data: ['message' => $this->messagePayload($message)],
      message: 'Message sent.',
      status: 201,
    );
  }

  public function listDocuments(CounsellingCase $counsellingCase): JsonResponse
  {
    $this->authorize('view', $counsellingCase);

    $documents = $counsellingCase->documents()
      ->with('uploadedBy')
      ->latest()
      ->get()
      ->map(fn (CounsellingDocument $document) => $this->documentPayload($document));

    return $this->responder->success(
      data: ['documents' => $documents],
      message: 'Documents retrieved.',
    );
  }

  public function uploadDocument(Request $request, CounsellingCase $counsellingCase): JsonResponse
  {
    $this->authorize('update', $counsellingCase);

    $validated = $request->validate([
      'file' => ['required', 'file', 'max:10240', 'mimes:pdf,doc,docx,jpg,jpeg,png,webp,zip'],
      'title' => ['nullable', 'string', 'max:255'],
      'visibility' => ['nullable', 'string', Rule::in(['case', 'client', 'counsellor'])],
    ]);

    $file = $request->file('file');
    $path = $file->store('counselling/'.$counsellingCase->uuid, 'local');

    $document = CounsellingDocument::query()->create([
      'case_id' => $counsellingCase->id,
      'uploaded_by_user_id' => $request->user()->id,
      'title' => $validated['title'] ?? $file->getClientOriginalName(),
      'disk_path' => $path,
      'mime_type' => $file->getClientMimeType(),
      'size_bytes' => $file->getSize(),
      'visibility' => $validated['visibility'] ?? 'case',
    ]);

    return $this->responder->success(
      data: ['document' => $this->documentPayload($document->load('uploadedBy'))],
      message: 'Document uploaded.',
      status: 201,
    );
  }

  public function requirePayment(
    Request $request,
    CounsellingCase $counsellingCase,
    CounsellingPaymentService $payments,
  ): JsonResponse {
    $this->authorize('update', $counsellingCase);

    $validated = $request->validate([
      'amount' => ['required', 'numeric', 'min:0'],
      'currency' => ['nullable', 'string', 'size:3'],
      'payment_type' => ['nullable', 'string', Rule::in(['paid', 'scholarship', 'discounted', 'free'])],
      'note' => ['nullable', 'string', 'max:2000'],
    ]);

    $paymentType = $validated['payment_type'] ?? 'paid';
    if ($paymentType === 'free' || (float) $validated['amount'] <= 0) {
      $counsellingCase->status = CaseStatus::UnderReview;
      $counsellingCase->metadata = array_merge($counsellingCase->metadata ?? [], [
        'payment_decision' => 'free',
        'payment_note' => $validated['note'] ?? null,
      ]);
      $counsellingCase->save();

      return $this->responder->success(
        data: ['case' => new CounsellingCaseResource($counsellingCase->fresh(['service', 'latestPayment']))],
        message: 'Case marked free — no payment required.',
      );
    }

    $payment = $payments->createManualInvoice($counsellingCase, [
      'amount' => (float) $validated['amount'],
      'currency' => $validated['currency'] ?? 'USD',
      'payment_type' => $paymentType,
      'note' => $validated['note'] ?? null,
    ], $request->user());

    $counsellingCase->status = CaseStatus::WaitingPayment;
    $counsellingCase->metadata = array_merge($counsellingCase->metadata ?? [], [
      'payment_decision' => $paymentType,
      'payment_note' => $validated['note'] ?? null,
    ]);
    $counsellingCase->save();

    return $this->responder->success(
      data: [
        'case' => new CounsellingCaseResource($counsellingCase->fresh(['service', 'latestPayment'])),
        'payment' => [
          'id' => $payment->uuid,
          'status' => $payment->status instanceof \BackedEnum ? $payment->status->value : $payment->status,
          'amount' => (float) $payment->amount,
          'currency' => $payment->currency,
        ],
      ],
      message: 'Payment invoice generated.',
      status: 201,
    );
  }

  public function waivePayment(Request $request, CounsellingCase $counsellingCase): JsonResponse
  {
    $this->authorize('update', $counsellingCase);

    $validated = $request->validate([
      'note' => ['nullable', 'string', 'max:2000'],
    ]);

    $counsellingCase->status = $counsellingCase->counsellor_id
      ? CaseStatus::Assigned
      : CaseStatus::UnderReview;
    $counsellingCase->metadata = array_merge($counsellingCase->metadata ?? [], [
      'payment_decision' => 'waived',
      'payment_note' => $validated['note'] ?? null,
      'payment_waived_at' => now()->toIso8601String(),
    ]);
    $counsellingCase->save();

    app(\App\Modules\Counselling\Services\CounsellingAuditService::class)->record(
      $counsellingCase,
      $request->user(),
      'payment.waived',
      'Payment Waived',
      $validated['note'] ?? 'Admin waived payment for this case.',
    );

    return $this->responder->success(
      data: ['case' => new CounsellingCaseResource($counsellingCase->fresh([
        'service',
        'counsellor.user',
        'latestPayment',
        'events.actor',
      ]))],
      message: 'Payment waived.',
    );
  }

  public function indexAppointments(Request $request): JsonResponse
  {
    $this->authorizeManageOrView($request);

    $query = CounsellingAppointment::query()
      ->with(['case.service', 'counsellor.user'])
      ->latest('starts_at');

    if ($status = $request->query('status')) {
      $query->where('status', (string) $status);
    }

    $perPage = min(100, max(1, (int) $request->query('per_page', 25)));
    $paginator = $query->paginate($perPage);

    return $this->responder->success(
      data: [
        'data' => collect($paginator->items())->map(fn (CounsellingAppointment $appointment) => [
          'id' => $appointment->uuid,
          'status' => $appointment->status instanceof \BackedEnum ? $appointment->status->value : $appointment->status,
          'starts_at' => $appointment->starts_at?->toIso8601String(),
          'ends_at' => $appointment->ends_at?->toIso8601String(),
          'format' => $appointment->format instanceof \BackedEnum ? $appointment->format->value : $appointment->format,
          'meeting_link' => $appointment->meeting_link,
          'location' => $appointment->location,
          'case' => $appointment->case ? [
            'id' => $appointment->case->uuid,
            'case_number' => $appointment->case->case_number,
            'client_name' => $appointment->case->client_name,
          ] : null,
          'counsellor' => $appointment->counsellor ? [
            'id' => $appointment->counsellor->uuid,
            'display_name' => $appointment->counsellor->display_name,
          ] : null,
        ])->values(),
        'meta' => [
          'current_page' => $paginator->currentPage(),
          'last_page' => $paginator->lastPage(),
          'per_page' => $paginator->perPage(),
          'total' => $paginator->total(),
        ],
      ],
      message: 'Appointments retrieved.',
    );
  }

  public function indexAssignments(Request $request): JsonResponse
  {
    $this->authorizeManageOrView($request);

    $query = CounsellingCase::query()
      ->with(['service', 'counsellor.user'])
      ->whereNotNull('counsellor_id')
      ->latest('assigned_at');

    $perPage = min(100, max(1, (int) $request->query('per_page', 25)));

    return $this->responder->success(
      data: PaginatedResponseBuilder::fromPaginator(
        $query->paginate($perPage),
        CounsellingCaseResource::class,
      ),
      message: 'Assignments retrieved.',
    );
  }

  public function settings(Request $request): JsonResponse
  {
    $this->authorize('permission', 'counselling.manage');

    return $this->responder->success(
      data: [
        'settings' => [
          'require_auth_for_requests' => true,
          'hide_public_pricing' => true,
          'default_timezone' => config('app.timezone', 'UTC'),
          'allow_client_cancel' => true,
          'allow_client_reschedule' => true,
          'supported_formats' => ['virtual', 'physical', 'phone', 'video', 'hybrid'],
          'statuses' => collect(CaseStatus::cases())
            ->reject(fn (CaseStatus $status) => in_array($status, [
              CaseStatus::Pending,
              CaseStatus::Scheduled,
              CaseStatus::Confirmed,
              CaseStatus::Session1,
              CaseStatus::Session2,
              CaseStatus::Session3,
              CaseStatus::OnHold,
              CaseStatus::Escalated,
            ], true))
            ->map(fn (CaseStatus $status) => [
              'value' => $status->value,
              'label' => $status->label(),
            ])
            ->values()
            ->all(),
        ],
      ],
      message: 'Counselling settings retrieved.',
    );
  }

  public function updateSettings(Request $request): JsonResponse
  {
    $this->authorize('permission', 'counselling.manage');

    $validated = $request->validate([
      'default_timezone' => ['nullable', 'string', 'max:64'],
      'allow_client_cancel' => ['sometimes', 'boolean'],
      'allow_client_reschedule' => ['sometimes', 'boolean'],
    ]);

    return $this->responder->success(
      data: ['settings' => array_merge([
        'require_auth_for_requests' => true,
        'hide_public_pricing' => true,
      ], $validated)],
      message: 'Counselling settings updated.',
    );
  }

  private function authorizeManageOrView(Request $request): void
  {
    abort_unless(
      $request->user()?->hasAnyPermission(['counselling.manage', 'counselling.view']) ?? false,
      403,
      'This action is unauthorized.',
    );
  }

  /**
   * @param  list<array<string, mixed>>  $availability
   */
  private function syncAvailability(Counsellor $counsellor, array $availability): void
  {
    $counsellor->availability()->delete();

    foreach ($availability as $slot) {
      $counsellor->availability()->create([
        'weekday' => (int) $slot['weekday'],
        'starts_at' => (string) $slot['starts_at'],
        'ends_at' => (string) $slot['ends_at'],
        'timezone' => (string) ($slot['timezone'] ?? 'UTC'),
        'is_active' => (bool) ($slot['is_active'] ?? true),
      ]);
    }
  }

  private function uniqueCounsellorSlug(string $slug, ?int $ignoreId = null): string
  {
    $base = Str::slug($slug) ?: 'counsellor';
    $candidate = $base;
    $i = 1;

    while (
      Counsellor::query()
        ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
        ->where('slug', $candidate)
        ->exists()
    ) {
      $candidate = $base.'-'.$i;
      $i++;
    }

    return $candidate;
  }

  /**
   * @return array<string, mixed>
   */
  private function appointmentPayload(CounsellingAppointment $appointment): array
  {
    return [
      'id' => $appointment->uuid,
      'session_number' => (int) $appointment->session_number,
      'status' => $appointment->status instanceof \BackedEnum ? $appointment->status->value : $appointment->status,
      'format' => $appointment->format instanceof \BackedEnum ? $appointment->format->value : $appointment->format,
      'starts_at' => $appointment->starts_at?->toIso8601String(),
      'ends_at' => $appointment->ends_at?->toIso8601String(),
      'timezone' => $appointment->timezone,
      'meeting_link' => $appointment->meeting_link,
      'location' => $appointment->location,
      'attended_at' => $appointment->attended_at?->toIso8601String(),
    ];
  }

  /**
   * @return array<string, mixed>
   */
  private function notePayload(CounsellingNote $note): array
  {
    return [
      'id' => $note->uuid,
      'body' => $note->body,
      'visibility' => $note->visibility instanceof \BackedEnum ? $note->visibility->value : $note->visibility,
      'author' => $note->relationLoaded('author') ? [
        'id' => $note->author?->uuid,
        'name' => $note->author?->name,
      ] : null,
      'created_at' => $note->created_at?->toIso8601String(),
    ];
  }

  /**
   * @return array<string, mixed>
   */
  private function messagePayload(\App\Modules\Counselling\Models\CounsellingMessage $message): array
  {
    return [
      'id' => $message->uuid,
      'body' => $message->body,
      'sender_role' => $message->sender_role,
      'attachments' => $message->attachments,
      'sender' => $message->relationLoaded('sender') ? [
        'id' => $message->sender?->uuid,
        'name' => $message->sender?->name,
      ] : null,
      'created_at' => $message->created_at?->toIso8601String(),
    ];
  }

  /**
   * @return array<string, mixed>
   */
  private function documentPayload(CounsellingDocument $document): array
  {
    return [
      'id' => $document->uuid,
      'title' => $document->title,
      'mime_type' => $document->mime_type,
      'size_bytes' => $document->size_bytes !== null ? (int) $document->size_bytes : null,
      'visibility' => $document->visibility,
      'uploaded_by' => $document->relationLoaded('uploadedBy') ? [
        'id' => $document->uploadedBy?->uuid,
        'name' => $document->uploadedBy?->name,
      ] : null,
      'created_at' => $document->created_at?->toIso8601String(),
    ];
  }
}
