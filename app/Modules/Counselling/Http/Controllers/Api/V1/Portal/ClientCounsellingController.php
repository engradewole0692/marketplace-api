<?php

declare(strict_types=1);

namespace App\Modules\Counselling\Http\Controllers\Api\V1\Portal;

use App\Http\Controllers\Api\V1\ApiController;
use App\Models\User;
use App\Modules\Counselling\Enums\NoteVisibility;
use App\Modules\Counselling\Http\Resources\CounsellingCaseResource;
use App\Modules\Counselling\Models\CounsellingCase;
use App\Modules\Counselling\Models\CounsellingDocument;
use App\Modules\Counselling\Models\CounsellingFeedback;
use App\Modules\Counselling\Models\CounsellingNote;
use App\Modules\Counselling\Services\CounsellingCaseService;
use App\Modules\Counselling\Services\CounsellingMessagingService;
use App\Modules\Counselling\Services\CounsellingPaymentService;
use App\Support\Api\PaginatedResponseBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ClientCounsellingController extends ApiController
{
  public function myCases(Request $request): JsonResponse
  {
    $user = $request->user();
    $query = CounsellingCase::query()
      ->with(['service', 'counsellor.user', 'latestPayment', 'nextAppointment'])
      ->where(function ($q) use ($user): void {
        $q->where('user_id', $user->id)
          ->orWhereRaw('LOWER(client_email) = ?', [strtolower((string) $user->email)]);
      })
      ->latest();

    $perPage = min(100, max(1, (int) $request->query('per_page', 25)));

    return $this->responder->success(
      data: PaginatedResponseBuilder::fromPaginator(
        $query->paginate($perPage),
        CounsellingCaseResource::class,
      ),
      message: 'Your counselling cases retrieved.',
    );
  }

  public function showCase(Request $request, CounsellingCase $counsellingCase): JsonResponse
  {
    $this->assertOwnsCase($request->user(), $counsellingCase);

    $counsellingCase->load([
      'service.category',
      'counsellor.user',
      'latestPayment',
      'nextAppointment',
      'appointments',
      'events.actor',
      'documents',
    ]);

    return $this->responder->success(
      data: ['case' => new CounsellingCaseResource($counsellingCase)],
      message: 'Counselling case retrieved.',
    );
  }

  public function payCase(
    Request $request,
    CounsellingCase $counsellingCase,
    CounsellingPaymentService $payments,
  ): JsonResponse {
    $this->assertOwnsCase($request->user(), $counsellingCase);

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
      message: 'Payment confirmation submitted.',
    );
  }

  /**
   * Initiate PayPal checkout for a counselling case payment.
   */
  public function checkoutCase(
    Request $request,
    CounsellingCase $counsellingCase,
    CounsellingPaymentService $payments,
  ): JsonResponse {
    $this->assertOwnsCase($request->user(), $counsellingCase);

    $validated = $request->validate([
      'payment_method' => ['nullable', 'string'],
      'country' => ['nullable', 'string'],
      'country_id' => ['nullable', 'string'],
    ]);

    $result = $payments->checkout($counsellingCase, $validated, $request, $request->user());

    return $this->responder->success(
      data: [
        'checkout' => $result['checkout'],
        'payment' => [
          'id' => $result['payment']->uuid,
          'amount' => (float) $result['payment']->amount,
          'currency' => $result['payment']->currency,
          'status' => $result['payment']->status instanceof \BackedEnum
            ? $result['payment']->status->value
            : $result['payment']->status,
        ],
        'donation_id' => $result['donation']->uuid,
      ],
      message: 'Counselling payment checkout created.',
    );
  }

  public function updateSchedulePreference(Request $request, CounsellingCase $counsellingCase): JsonResponse
  {
    $this->assertOwnsCase($request->user(), $counsellingCase);

    $validated = $request->validate([
      'preferred_at' => ['required', 'date'],
      'timezone' => ['nullable', 'string', 'max:64'],
      'note' => ['nullable', 'string', 'max:2000'],
    ]);

    $counsellingCase->preferred_at = $validated['preferred_at'];
    if (array_key_exists('timezone', $validated)) {
      $counsellingCase->timezone = $validated['timezone'];
    }
    $counsellingCase->metadata = array_merge($counsellingCase->metadata ?? [], [
      'schedule_preference_updated_at' => now()->toIso8601String(),
      'schedule_preference_note' => $validated['note'] ?? null,
    ]);
    $counsellingCase->save();

    return $this->responder->success(
      data: ['case' => new CounsellingCaseResource($counsellingCase->fresh(['service', 'latestPayment']))],
      message: 'Schedule preference updated.',
    );
  }

  public function requestReschedule(Request $request, CounsellingCase $counsellingCase): JsonResponse
  {
    $this->assertOwnsCase($request->user(), $counsellingCase);

    if (! $counsellingCase->allow_reschedule) {
      abort(422, 'Rescheduling is not allowed for this case.');
    }

    $validated = $request->validate([
      'preferred_at' => ['required', 'date'],
      'timezone' => ['nullable', 'string', 'max:64'],
      'reason' => ['nullable', 'string', 'max:2000'],
    ]);

    $counsellingCase->preferred_at = $validated['preferred_at'];
    if (array_key_exists('timezone', $validated)) {
      $counsellingCase->timezone = $validated['timezone'];
    }
    $counsellingCase->metadata = array_merge($counsellingCase->metadata ?? [], [
      'reschedule_requested_at' => now()->toIso8601String(),
      'reschedule_reason' => $validated['reason'] ?? null,
    ]);
    $counsellingCase->save();

    return $this->responder->success(
      data: ['case' => new CounsellingCaseResource($counsellingCase->fresh(['service', 'nextAppointment']))],
      message: 'Reschedule request submitted.',
    );
  }

  public function cancelCase(
    Request $request,
    CounsellingCase $counsellingCase,
    CounsellingCaseService $caseService,
  ): JsonResponse {
    $this->assertOwnsCase($request->user(), $counsellingCase);

    if (! $counsellingCase->allow_cancel) {
      abort(422, 'Cancellation is not allowed for this case.');
    }

    $validated = $request->validate([
      'reason' => ['nullable', 'string', 'max:2000'],
    ]);

    $counsellingCase = $caseService->cancel($counsellingCase, $request->user(), $validated['reason'] ?? null);

    return $this->responder->success(
      data: ['case' => new CounsellingCaseResource($counsellingCase)],
      message: 'Case cancelled.',
    );
  }

  public function listMessages(
    Request $request,
    CounsellingCase $counsellingCase,
    CounsellingMessagingService $messaging,
  ): JsonResponse {
    $this->assertOwnsCase($request->user(), $counsellingCase);

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
    $this->assertOwnsCase($request->user(), $counsellingCase);

    $validated = $request->validate([
      'body' => ['required', 'string', 'max:5000'],
      'attachments' => ['nullable', 'array'],
    ]);

    $message = $messaging->sendMessage($counsellingCase, $request->user(), [
      ...$validated,
      'sender_role' => 'client',
    ]);

    return $this->responder->success(
      data: ['message' => $this->messagePayload($message)],
      message: 'Message sent.',
      status: 201,
    );
  }

  public function listNotes(Request $request, CounsellingCase $counsellingCase): JsonResponse
  {
    $this->assertOwnsCase($request->user(), $counsellingCase);

    $notes = $counsellingCase->notes()
      ->with('author')
      ->where('visibility', NoteVisibility::Client->value)
      ->latest()
      ->get()
      ->map(fn (CounsellingNote $note) => $this->notePayload($note));

    return $this->responder->success(
      data: ['notes' => $notes],
      message: 'Notes retrieved.',
    );
  }

  public function submitFeedback(Request $request, CounsellingCase $counsellingCase): JsonResponse
  {
    $this->assertOwnsCase($request->user(), $counsellingCase);

    $validated = $request->validate([
      'rating' => ['required', 'integer', 'min:1', 'max:5'],
      'comment' => ['nullable', 'string', 'max:2000'],
    ]);

    $feedback = CounsellingFeedback::query()->updateOrCreate(
      ['case_id' => $counsellingCase->id, 'user_id' => $request->user()->id],
      [
        'rating' => (int) $validated['rating'],
        'comment' => $validated['comment'] ?? null,
      ],
    );

    return $this->responder->success(
      data: [
        'feedback' => [
          'id' => $feedback->uuid,
          'rating' => (int) $feedback->rating,
          'comment' => $feedback->comment,
          'created_at' => $feedback->created_at?->toIso8601String(),
        ],
      ],
      message: 'Feedback submitted.',
      status: 201,
    );
  }

  public function listDocuments(Request $request, CounsellingCase $counsellingCase): JsonResponse
  {
    $this->assertOwnsCase($request->user(), $counsellingCase);

    $documents = $counsellingCase->documents()
      ->whereIn('visibility', ['case', 'client'])
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
    $this->assertOwnsCase($request->user(), $counsellingCase);

    $validated = $request->validate([
      'file' => ['required', 'file', 'max:10240', 'mimes:pdf,doc,docx,jpg,jpeg,png,webp,zip'],
      'title' => ['nullable', 'string', 'max:255'],
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
      'visibility' => 'client',
    ]);

    return $this->responder->success(
      data: ['document' => $this->documentPayload($document)],
      message: 'Document uploaded.',
      status: 201,
    );
  }

  private function assertOwnsCase(User $user, CounsellingCase $case): void
  {
    if ((int) $case->user_id === (int) $user->id) {
      return;
    }

    if (strcasecmp((string) $case->client_email, (string) $user->email) === 0) {
      return;
    }

    abort(403, 'You do not have access to this case.');
  }

  /**
   * @return array<string, mixed>
   */
  private function notePayload(CounsellingNote $note): array
  {
    return [
      'id' => $note->uuid,
      'body' => $note->body,
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
      'created_at' => $document->created_at?->toIso8601String(),
    ];
  }
}
