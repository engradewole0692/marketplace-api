<?php

declare(strict_types=1);

namespace App\Modules\Events\Support;

use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Services\EventAuthorizationService;

trait ChecksEventScope
{
  protected function eventIsAccessible(User $user, ?Event $event): bool
  {
    return app(EventAuthorizationService::class)->canAccessEvent($user, $event);
  }
}
