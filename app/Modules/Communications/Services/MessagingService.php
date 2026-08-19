<?php

declare(strict_types=1);

namespace App\Modules\Communications\Services;

use App\Models\User;
use App\Modules\Communications\Models\PlatformConversation;
use App\Modules\Communications\Models\PlatformMessage;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

final class MessagingService
{
  /**
   * Get all conversations where the user is a participant.
   */
  public function conversationsForUser(User $user, int $perPage = 20): LengthAwarePaginator
  {
    return PlatformConversation::query()
      ->whereHas('participants', fn ($q) => $q->where('user_id', $user->id))
      ->with(['participants:id,uuid,name,email', 'latestMessage'])
      ->orderByDesc('last_message_at')
      ->paginate($perPage);
  }

  /**
   * Start or find an existing direct conversation between two users.
   * Enforces permission: the initiating admin must have 'communications.manage'
   * or the conversation must be module-scoped.
   */
  public function findOrCreateDirect(User $userA, User $userB, ?string $subject = null, ?array $module = null): PlatformConversation
  {
    // Look for existing direct conversation with both participants.
    $existing = PlatformConversation::query()
      ->where('type', 'direct')
      ->whereHas('participants', fn ($q) => $q->where('user_id', $userA->id))
      ->whereHas('participants', fn ($q) => $q->where('user_id', $userB->id))
      ->when($module !== null, fn ($q) => $q
        ->where('module', $module['module'] ?? null)
        ->where('module_entity_type', $module['entity_type'] ?? null)
        ->where('module_entity_id', $module['entity_id'] ?? null),
      )
      ->first();

    if ($existing !== null) {
      return $existing;
    }

    $conversation = PlatformConversation::query()->create([
      'uuid' => Str::uuid()->toString(),
      'type' => 'direct',
      'subject' => $subject,
      'module' => $module['module'] ?? null,
      'module_entity_type' => $module['entity_type'] ?? null,
      'module_entity_id' => $module['entity_id'] ?? null,
    ]);

    $conversation->participants()->attach([
      $userA->id => ['role' => 'owner'],
      $userB->id => ['role' => 'participant'],
    ]);

    return $conversation;
  }

  public function sendMessage(PlatformConversation $conversation, User $sender, string $body, ?array $attachments = null): PlatformMessage
  {
    $this->assertParticipant($conversation, $sender);

    $message = PlatformMessage::query()->create([
      'uuid' => Str::uuid()->toString(),
      'conversation_id' => $conversation->id,
      'sender_id' => $sender->id,
      'body' => $body,
      'type' => 'text',
      'attachments' => $attachments,
    ]);

    $conversation->last_message_at = now();
    $conversation->save();

    return $message->load('sender:id,uuid,name,email');
  }

  public function messages(PlatformConversation $conversation, User $user, int $perPage = 30): LengthAwarePaginator
  {
    $this->assertParticipant($conversation, $user);

    // Mark as read for this user.
    $conversation->participants()->updateExistingPivot($user->id, ['last_read_at' => now()]);

    return PlatformMessage::query()
      ->where('conversation_id', $conversation->id)
      ->where('is_deleted', false)
      ->with('sender:id,uuid,name,email')
      ->orderByDesc('created_at')
      ->paginate($perPage);
  }

  public function deleteMessage(PlatformMessage $message, User $actor): void
  {
    if ($message->sender_id !== $actor->id && ! $actor->hasAnyPermission(['communications.manage'])) {
      abort(403, 'You cannot delete this message.');
    }

    $message->is_deleted = true;
    $message->save();
  }

  public function canParticipate(PlatformConversation $conversation, User $user): bool
  {
    return $conversation->participants()->where('user_id', $user->id)->exists();
  }

  private function assertParticipant(PlatformConversation $conversation, User $user): void
  {
    if (! $this->canParticipate($conversation, $user)) {
      abort(403, 'You are not a participant in this conversation.');
    }
  }
}
