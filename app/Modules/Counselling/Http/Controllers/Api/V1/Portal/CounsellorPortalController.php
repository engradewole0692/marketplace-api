<?php

declare(strict_types=1);

namespace App\Modules\Counselling\Http\Controllers\Api\V1\Portal;

use App\Http\Controllers\Api\V1\ApiController;
use App\Models\User;
use App\Modules\Counselling\Enums\NoteVisibility;
use App\Modules\Counselling\Http\Resources\CounsellingCaseResource;
use App\Modules\Counselling\Models\CounsellingAppointment;
use App\Modules\Counselling\Models\CounsellingCase;
use App\Modules\Counselling\Models\CounsellingNote;
use App\Modules\Counselling\Models\Counsellor;
use App\Modules\Counselling\Services\CounsellingAppointmentService;
use App\Modules\Counselling\Services\CounsellingMessagingService;
use App\Support\Api\PaginatedResponseBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

final class CounsellorPortalController extends ApiController
{
  public function myCases(Request $request): JsonResponse
  {
    $this->authorizeCounsellorPortal($request);

    $counsellor = $this->resolveCounsellor($request->user());
    $query = CounsellingCase::query()
      ->with(['service', 'latestPayment', 'nextAppointment'])
      ->where('counsellor_id', $counsellor->id)
      ->latest();

    if ($status = $request->query('status')) {
      $query->where('status', (string) $status);
    }

    $perPage = min(100, max(1, (int) $request->query('per_page', 25)));

    return $this->responder->success(
      data: PaginatedResponseBuilder::fromPaginator(
        $query->paginate($perPage),
        CounsellingCaseResource::class,
      ),
      message: 'Assigned cases retrieved.',
    );
  }

  public function todayAppointments(Request $request): JsonResponse
  {
    $this->authorizeCounsellorPortal($request);

    $counsellor = $this->resolveCounsellor($request->user());
    $start = Carbon::today();
    $end = Carbon::today()->endOfDay();

    $appointments = CounsellingAppointment::query()
      ->with(['case.service', 'counsellor.user'])
      ->where('counsellor_id', $counsellor->id)
      ->whereBetween('starts_at', [$start, $end])
      ->whereNotIn('status', ['cancelled', 'rescheduled'])
      ->orderBy('starts_at')
      ->get()
      ->map(fn (CounsellingAppointment $appointment) => $this->appointmentPayload($appointment));

    return $this->responder->success(
      data: ['appointments' => $appointments],
      message: 'Today\'s appointments retrieved.',
    );
  }

  public function addCounsellorNote(Request $request, CounsellingCase $counsellingCase): JsonResponse
  {
    $this->authorizeCounsellorPortal($request);
    $counsellor = $this->resolveCounsellor($request->user());
    $this->assertAssignedCounsellor($counsellor, $counsellingCase);

    $validated = $request->validate([
      'body' => ['required', 'string', 'max:5000'],
      'visibility' => ['sometimes', 'string', Rule::in(['counsellor', 'client'])],
    ]);

    $note = CounsellingNote::query()->create([
      'case_id' => $counsellingCase->id,
      'author_user_id' => $request->user()->id,
      'visibility' => NoteVisibility::from($validated['visibility'] ?? 'counsellor'),
      'body' => $validated['body'],
    ]);

    return $this->responder->success(
      data: ['note' => $this->notePayload($note->load('author'))],
      message: 'Note added.',
      status: 201,
    );
  }

  public function sendMessage(
    Request $request,
    CounsellingCase $counsellingCase,
    CounsellingMessagingService $messaging,
  ): JsonResponse {
    $this->authorizeCounsellorPortal($request);
    $counsellor = $this->resolveCounsellor($request->user());
    $this->assertAssignedCounsellor($counsellor, $counsellingCase);

    $validated = $request->validate([
      'body' => ['required', 'string', 'max:5000'],
      'attachments' => ['nullable', 'array'],
    ]);

    $message = $messaging->sendMessage($counsellingCase, $request->user(), [
      ...$validated,
      'sender_role' => 'counsellor',
    ]);

    return $this->responder->success(
      data: ['message' => $this->messagePayload($message)],
      message: 'Message sent.',
      status: 201,
    );
  }

  public function listNotes(Request $request, CounsellingCase $counsellingCase): JsonResponse
  {
    $this->authorizeCounsellorPortal($request);
    $counsellor = $this->resolveCounsellor($request->user());
    $this->assertAssignedCounsellor($counsellor, $counsellingCase);

    $notes = $counsellingCase->notes()
      ->with('author')
      ->whereIn('visibility', [NoteVisibility::Counsellor, NoteVisibility::Client])
      ->latest()
      ->limit(100)
      ->get()
      ->map(fn (CounsellingNote $note) => $this->notePayload($note))
      ->values();

    return $this->responder->success(
      data: ['notes' => $notes],
      message: 'Notes retrieved.',
    );
  }

  public function listMessages(
    Request $request,
    CounsellingCase $counsellingCase,
    CounsellingMessagingService $messaging,
  ): JsonResponse {
    $this->authorizeCounsellorPortal($request);
    $counsellor = $this->resolveCounsellor($request->user());
    $this->assertAssignedCounsellor($counsellor, $counsellingCase);

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

  public function markAppointmentAttendance(
    Request $request,
    CounsellingAppointment $appointment,
    CounsellingAppointmentService $appointments,
  ): JsonResponse {
    $this->authorizeCounsellorPortal($request);
    $counsellor = $this->resolveCounsellor($request->user());

    $appointment->loadMissing('case');
    if ((int) $appointment->counsellor_id !== (int) $counsellor->id) {
      abort(403, 'You are not assigned to this appointment.');
    }

    $validated = $request->validate([
      'attended' => ['sometimes', 'boolean'],
      'status' => ['sometimes', 'string', Rule::in(['completed', 'missed', 'attended'])],
    ]);

    $attended = array_key_exists('attended', $validated)
      ? (bool) $validated['attended']
      : in_array((string) ($validated['status'] ?? ''), ['completed', 'attended'], true);

    $appointment = $attended
      ? $appointments->markCompleted($appointment, $request->user())
      : $appointments->markMissed($appointment, $request->user());

    return $this->responder->success(
      data: ['appointment' => $this->appointmentPayload($appointment)],
      message: $attended ? 'Attendance marked as attended.' : 'Attendance marked as missed.',
    );
  }

  public function showCase(Request $request, CounsellingCase $counsellingCase): JsonResponse
  {
    $this->authorizeCounsellorPortal($request);
    $counsellor = $this->resolveCounsellor($request->user());
    $this->assertAssignedCounsellor($counsellor, $counsellingCase);

    $counsellingCase->load([
      'service.category',
      'category',
      'latestPayment',
      'nextAppointment',
      'appointments',
      'events.actor',
    ]);

    return $this->responder->success(
      data: ['case' => new CounsellingCaseResource($counsellingCase)],
      message: 'Assigned case retrieved.',
    );
  }

  public function appointments(Request $request): JsonResponse
  {
    $this->authorizeCounsellorPortal($request);
    $counsellor = $this->resolveCounsellor($request->user());

    $query = CounsellingAppointment::query()
      ->with(['case.service'])
      ->where('counsellor_id', $counsellor->id)
      ->whereNotIn('status', ['cancelled'])
      ->orderBy('starts_at');

    $perPage = min(100, max(1, (int) $request->query('per_page', 50)));
    $paginator = $query->paginate($perPage);

    return $this->responder->success(
      data: [
        'data' => collect($paginator->items())->map(fn ($appointment) => $this->appointmentPayload($appointment))->values(),
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

  public function availability(Request $request): JsonResponse
  {
    $this->authorizeCounsellorPortal($request);
    $counsellor = $this->resolveCounsellor($request->user());
    $counsellor->load('availability');

    return $this->responder->success(
      data: [
        'availability' => $counsellor->availability->map(fn ($slot) => [
          'weekday' => (int) $slot->weekday,
          'starts_at' => (string) $slot->starts_at,
          'ends_at' => (string) $slot->ends_at,
          'timezone' => $slot->timezone,
          'is_active' => (bool) $slot->is_active,
        ])->values(),
      ],
      message: 'Availability retrieved.',
    );
  }

  public function updateAvailability(Request $request): JsonResponse
  {
    $this->authorizeCounsellorPortal($request);
    $counsellor = $this->resolveCounsellor($request->user());

    $validated = $request->validate([
      'availability' => ['required', 'array'],
      'availability.*.weekday' => ['required', 'integer', 'min:0', 'max:6'],
      'availability.*.starts_at' => ['required', 'string', 'max:20'],
      'availability.*.ends_at' => ['required', 'string', 'max:20'],
      'availability.*.timezone' => ['nullable', 'string', 'max:64'],
      'availability.*.is_active' => ['sometimes', 'boolean'],
    ]);

    $counsellor->availability()->delete();
    foreach ($validated['availability'] as $slot) {
      $counsellor->availability()->create([
        'weekday' => (int) $slot['weekday'],
        'starts_at' => (string) $slot['starts_at'],
        'ends_at' => (string) $slot['ends_at'],
        'timezone' => (string) ($slot['timezone'] ?? 'UTC'),
        'is_active' => (bool) ($slot['is_active'] ?? true),
      ]);
    }

    return $this->availability($request);
  }

  public function recommendFollowUp(Request $request, CounsellingCase $counsellingCase): JsonResponse
  {
    $this->authorizeCounsellorPortal($request);
    $counsellor = $this->resolveCounsellor($request->user());
    $this->assertAssignedCounsellor($counsellor, $counsellingCase);

    $validated = $request->validate([
      'note' => ['nullable', 'string', 'max:2000'],
    ]);

    $counsellingCase->status = \App\Modules\Counselling\Enums\CaseStatus::FollowUpRequired;
    $counsellingCase->metadata = array_merge($counsellingCase->metadata ?? [], [
      'follow_up_recommended_at' => now()->toIso8601String(),
      'follow_up_note' => $validated['note'] ?? null,
    ]);
    $counsellingCase->save();

    app(\App\Modules\Counselling\Services\CounsellingAuditService::class)->record(
      $counsellingCase,
      $request->user(),
      'case.follow_up_recommended',
      'Follow-up Required',
      $validated['note'] ?? 'Counsellor recommended a follow-up session.',
    );

    return $this->responder->success(
      data: ['case' => new CounsellingCaseResource($counsellingCase->fresh(['service', 'events.actor']))],
      message: 'Follow-up recommended.',
    );
  }

  public function closeSession(Request $request, CounsellingCase $counsellingCase): JsonResponse
  {
    $this->authorizeCounsellorPortal($request);
    $counsellor = $this->resolveCounsellor($request->user());
    $this->assertAssignedCounsellor($counsellor, $counsellingCase);

    $validated = $request->validate([
      'note' => ['nullable', 'string', 'max:2000'],
    ]);

    $counsellingCase->status = \App\Modules\Counselling\Enums\CaseStatus::Completed;
    $counsellingCase->completed_at = now();
    $counsellingCase->save();

    app(\App\Modules\Counselling\Services\CounsellingAuditService::class)->record(
      $counsellingCase,
      $request->user(),
      'case.session_completed',
      'Session Completed',
      $validated['note'] ?? 'Counsellor closed the counselling session.',
    );

    return $this->responder->success(
      data: ['case' => new CounsellingCaseResource($counsellingCase->fresh(['service', 'events.actor']))],
      message: 'Session closed.',
    );
  }

  private function authorizeCounsellorPortal(Request $request): void
  {
    $this->authorize('permission', 'counsellor.portal');
  }

  private function resolveCounsellor(User $user): Counsellor
  {
    $counsellor = Counsellor::query()->where('user_id', $user->id)->first();
    if ($counsellor === null) {
      abort(403, 'Counsellor profile not found.');
    }

    return $counsellor;
  }

  private function assertAssignedCounsellor(Counsellor $counsellor, CounsellingCase $case): void
  {
    if ((int) $case->counsellor_id !== (int) $counsellor->id) {
      abort(403, 'You are not assigned to this case.');
    }
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
      'case' => $appointment->relationLoaded('case') && $appointment->case !== null ? [
        'id' => $appointment->case->uuid,
        'case_number' => $appointment->case->case_number,
        'client_name' => $appointment->case->client_name,
      ] : null,
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
}
