<?php

declare(strict_types=1);

namespace App\Modules\Communications\Http\Controllers;

use App\Http\Controllers\Api\V1\ApiController;
use App\Modules\Communications\Models\PlatformConversation;
use App\Modules\Communications\Models\PlatformMessage;
use App\Modules\Communications\Services\MessagingService;
use App\Support\Api\PaginatedResponseBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class MessagingController extends ApiController
{
  public function conversations(Request $request, MessagingService $service): JsonResponse
  {
    $paginator = $service->conversationsForUser($request->user());

    return $this->responder->success(
      data: PaginatedResponseBuilder::fromPaginator($paginator, fn ($c) => $this->transformConversation($c)),
      message: 'Conversations retrieved.',
    );
  }

  public function startConversation(Request $request, MessagingService $service): JsonResponse
  {
    $validated = $request->validate([
      'recipient_id' => ['required', 'string'],
      'subject' => ['nullable', 'string', 'max:255'],
      'message' => ['required', 'string', 'max:10000'],
      'module' => ['nullable', 'string', 'max:60'],
      'module_entity_type' => ['nullable', 'string', 'max:100'],
      'module_entity_id' => ['nullable', 'string', 'max:100'],
    ]);

    $actor = $request->user();
    $recipient = \App\Models\User::query()->where('uuid', $validated['recipient_id'])->firstOrFail();

    // Permission check: admins can message anyone; non-admins restricted to assigned context.
    if (! $actor->hasAnyPermission(['communications.manage', 'admin.access'])) {
      // Non-admin users can only start conversations with admins.
      $isRecipientAdmin = $recipient->hasAnyPermission(['admin.access']);
      if (! $isRecipientAdmin) {
        abort(403, 'You can only message administrators.');
      }
    }

    $moduleCtx = ! empty($validated['module']) ? [
      'module' => $validated['module'],
      'entity_type' => $validated['module_entity_type'] ?? null,
      'entity_id' => $validated['module_entity_id'] ?? null,
    ] : null;

    $conversation = $service->findOrCreateDirect($actor, $recipient, $validated['subject'] ?? null, $moduleCtx);
    $message = $service->sendMessage($conversation, $actor, $validated['message']);

    return $this->responder->created(
      data: [
        'conversation' => $this->transformConversation($conversation),
        'message' => $this->transformMessage($message),
      ],
      message: 'Conversation started.',
    );
  }

  public function messages(Request $request, PlatformConversation $conversation, MessagingService $service): JsonResponse
  {
    $paginator = $service->messages($conversation, $request->user());

    return $this->responder->success(
      data: PaginatedResponseBuilder::fromPaginator($paginator, fn ($m) => $this->transformMessage($m)),
      message: 'Messages retrieved.',
    );
  }

  public function sendMessage(Request $request, PlatformConversation $conversation, MessagingService $service): JsonResponse
  {
    $validated = $request->validate([
      'body' => ['required', 'string', 'max:10000'],
    ]);

    $message = $service->sendMessage($conversation, $request->user(), $validated['body']);

    return $this->responder->created(
      data: ['message' => $this->transformMessage($message)],
      message: 'Message sent.',
    );
  }

  public function deleteMessage(Request $request, PlatformMessage $message, MessagingService $service): JsonResponse
  {
    $service->deleteMessage($message, $request->user());

    return $this->responder->success(message: 'Message deleted.');
  }

  private function transformConversation(PlatformConversation $c): array
  {
    return [
      'id' => $c->uuid,
      'type' => $c->type,
      'subject' => $c->subject,
      'module' => $c->module,
      'is_closed' => $c->is_closed,
      'last_message_at' => $c->last_message_at?->toIso8601String(),
      'participants' => $c->relationLoaded('participants')
        ? $c->participants->map(fn ($u) => ['id' => $u->uuid, 'name' => $u->name, 'email' => $u->email])
        : [],
      'created_at' => $c->created_at?->toIso8601String(),
    ];
  }

  private function transformMessage(PlatformMessage $m): array
  {
    return [
      'id' => $m->uuid,
      'body' => $m->is_deleted ? '[deleted]' : $m->body,
      'is_deleted' => $m->is_deleted,
      'attachments' => $m->attachments,
      'sender' => $m->relationLoaded('sender') ? [
        'id' => $m->sender->uuid,
        'name' => $m->sender->name,
        'email' => $m->sender->email,
      ] : null,
      'created_at' => $m->created_at?->toIso8601String(),
    ];
  }
}
