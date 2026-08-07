<?php

declare(strict_types=1);

namespace App\Modules\Events\Services;

use App\Contracts\ServiceContract;
use App\Models\User;
use App\Modules\Events\Enums\TimelineEventType;
use App\Modules\Events\Models\EventRegistration;
use App\Modules\Events\Models\EventRegistrationTimeline;

final class RegistrationTimelineService implements ServiceContract
{
  /**
   * @param  array<string, mixed>|null  $metadata
   */
  public function record(
    EventRegistration $registration,
    TimelineEventType $eventType,
    string $description,
    ?User $actor = null,
    ?array $metadata = null,
  ): void {
    EventRegistrationTimeline::query()->create([
      'registration_id' => $registration->id,
      'event_type' => $eventType->value,
      'description' => $description,
      'actor_id' => $actor?->id,
      'metadata' => $metadata,
      'occurred_at' => now(),
    ]);
  }
}
