<?php

declare(strict_types=1);

namespace App\Modules\Events\Support;

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
}
