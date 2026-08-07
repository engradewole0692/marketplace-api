<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Contracts\ServiceContract;
use App\Enums\AuthAuditEventType;
use App\Models\AuthenticationAuditLog;
use App\Models\User;
use Illuminate\Http\Request;

final class AuthAuditService implements ServiceContract
{
  /**
   * @param  array<string, mixed>|null  $metadata
   */
  public function record(
    AuthAuditEventType $eventType,
    ?User $user = null,
    ?string $email = null,
    ?array $metadata = null,
  ): AuthenticationAuditLog {
    $request = request();

    return AuthenticationAuditLog::query()->create([
      'user_id' => $user?->id,
      'event_type' => $eventType->value,
      'email' => $email ?? $user?->email,
      'ip_address' => $request instanceof Request ? $request->ip() : null,
      'user_agent' => $request instanceof Request ? (string) $request->userAgent() : null,
      'metadata' => $metadata,
      'created_at' => now(),
    ]);
  }
}
