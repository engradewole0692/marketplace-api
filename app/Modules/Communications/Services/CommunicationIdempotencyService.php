<?php

declare(strict_types=1);

namespace App\Modules\Communications\Services;

use App\Contracts\ServiceContract;
use App\Modules\Communications\Models\CommunicationIdempotencyKey;
use Illuminate\Database\QueryException;

final class CommunicationIdempotencyService implements ServiceContract
{
  public function alreadyDispatched(?string $key): bool
  {
    if ($key === null || $key === '') {
      return false;
    }

    return CommunicationIdempotencyKey::query()->where('idempotency_key', $key)->exists();
  }

  public function record(?string $key, string $eventKey): void
  {
    if ($key === null || $key === '') {
      return;
    }

    try {
      CommunicationIdempotencyKey::query()->firstOrCreate(
        ['idempotency_key' => $key],
        ['event_key' => $eventKey],
      );
    } catch (QueryException) {
      // Unique constraint race — treat as already dispatched.
    }
  }

  public function compose(string $base, ?string $suffix = null): string
  {
    return $suffix ? "{$base}:{$suffix}" : $base;
  }
}
