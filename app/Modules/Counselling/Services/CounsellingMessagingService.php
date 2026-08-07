<?php

declare(strict_types=1);

namespace App\Modules\Counselling\Services;

use App\Contracts\ServiceContract;
use App\Enums\ApiErrorCode;
use App\Exceptions\BusinessException;
use App\Models\User;
use App\Modules\Counselling\Models\CounsellingCase;
use App\Modules\Counselling\Models\CounsellingMessage;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final class CounsellingMessagingService implements ServiceContract
{
  public function __construct(
    private readonly CounsellingAuditService $auditService,
    private readonly CounsellingNotificationService $notificationService,
  ) {}

  /**
   * @param  array<string, mixed>  $data
   */
  public function sendMessage(CounsellingCase $case, User $sender, array $data): CounsellingMessage
  {
    $this->assertCanAccessCase($case, $sender);

    $message = CounsellingMessage::query()->create([
      'case_id' => $case->id,
      'sender_user_id' => $sender->id,
      'sender_role' => $data['sender_role'] ?? $this->resolveSenderRole($case, $sender),
      'body' => (string) ($data['body'] ?? ''),
      'attachments' => $data['attachments'] ?? null,
    ]);

    $this->auditService->record(
      $case,
      $sender,
      'message.sent',
      'Message sent',
      null,
      ['message_id' => $message->uuid],
    );

    $role = (string) $message->sender_role;
    $preview = mb_substr((string) $message->body, 0, 120);
    if ($role === 'client') {
      $this->notificationService->notifyMessageReceived($case, 'counsellor', $preview);
    } else {
      $this->notificationService->notifyMessageReceived($case, 'client', $preview);
    }

    return $message->fresh(['sender']);
  }

  /**
   * @param  array<string, mixed>  $filters
   */
  public function listForCase(CounsellingCase $case, User $viewer, array $filters = []): LengthAwarePaginator
  {
    $this->assertCanAccessCase($case, $viewer);

    return CounsellingMessage::query()
      ->with('sender')
      ->where('case_id', $case->id)
      ->latest()
      ->paginate(min(100, max(1, (int) ($filters['per_page'] ?? 25))));
  }

  private function assertCanAccessCase(CounsellingCase $case, User $user): void
  {
    if ($user->hasPermission('counselling.manage')) {
      return;
    }

    if ((int) $case->user_id === (int) $user->id) {
      return;
    }

    if (strcasecmp((string) $case->client_email, (string) $user->email) === 0) {
      return;
    }

    $case->loadMissing('counsellor.user');
    if ($case->counsellor !== null && (int) $case->counsellor->user_id === (int) $user->id) {
      return;
    }

    if ($user->hasPermission('counsellor.portal') && $case->counsellor !== null && (int) $case->counsellor->user_id === (int) $user->id) {
      return;
    }

    throw new BusinessException('You do not have access to this case.', ApiErrorCode::Forbidden, null, 403);
  }

  private function resolveSenderRole(CounsellingCase $case, User $sender): string
  {
    if ($sender->hasPermission('counselling.manage')) {
      return 'admin';
    }

    $case->loadMissing('counsellor');
    if ($case->counsellor !== null && (int) $case->counsellor->user_id === (int) $sender->id) {
      return 'counsellor';
    }

    return 'client';
  }
}
