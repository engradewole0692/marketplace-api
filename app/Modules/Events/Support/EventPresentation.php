<?php

declare(strict_types=1);

namespace App\Modules\Events\Support;

use App\Modules\Events\Models\Event;

final class EventPresentation
{
  public static function eventDate(?Event $event): ?string
  {
    if ($event === null || $event->starts_at === null) {
      return null;
    }

    if ($event->ends_at !== null && ! $event->starts_at->isSameDay($event->ends_at)) {
      return $event->starts_at->format('M j').' - '.$event->ends_at->format('M j, Y');
    }

    return $event->starts_at->format('M j, Y');
  }

  public static function eventTime(?Event $event): ?string
  {
    if ($event === null || $event->starts_at === null) {
      return null;
    }

    $time = $event->starts_at->format('g:i A');

    if ($event->ends_at !== null) {
      $time .= ' - '.$event->ends_at->format('g:i A');
    }

    return $time.' ('.$event->timezone.')';
  }

  public static function venue(?Event $event): ?string
  {
    return $event?->venue?->name;
  }
}
