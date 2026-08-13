<?php

declare(strict_types=1);

namespace App\Modules\Events\Support;

use App\Enums\ApiErrorCode;
use App\Exceptions\BusinessException;
use App\Modules\Events\Enums\EventStatus;
use App\Modules\Events\Enums\EventVisibility;
use App\Modules\Events\Models\Event;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class PublicEventAccess
{
  public static function ensure(Event $event): void
  {
    $visibility = $event->visibility instanceof EventVisibility
      ? $event->visibility
      : EventVisibility::from((string) $event->visibility);

    if ($visibility === EventVisibility::Private || $event->published_at === null) {
      throw new NotFoundHttpException('Event not found.');
    }
  }

  public static function ensureRegistrationAllowed(Event $event): void
  {
    self::ensure($event);

    $status = $event->status instanceof EventStatus
      ? $event->status
      : EventStatus::from((string) $event->status);

    if (! $status->acceptsRegistrations()) {
      throw new BusinessException(
        'Registration is not open for this event.',
        ApiErrorCode::UnprocessableEntity,
        null,
        422,
      );
    }

    if (! $event->is_registration_open) {
      throw new BusinessException(
        'Registration has closed for this event.',
        ApiErrorCode::UnprocessableEntity,
        null,
        422,
      );
    }

    if ($event->is_full) {
      throw new BusinessException(
        'This event is at capacity.',
        ApiErrorCode::UnprocessableEntity,
        null,
        422,
      );
    }
  }
}
