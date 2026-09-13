<?php

declare(strict_types=1);

namespace App\Modules\Events\Support;

use App\Modules\Events\Enums\EventStaffDomain;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Models\EventRegistration;
use App\Modules\Events\Services\EventAuthorizationService;

trait AuthorizesEventStaffDomain
{
    protected function assertEventDomain(Event $event, EventStaffDomain $domain): void
    {
        app(EventAuthorizationService::class)->assertDomain(request()->user(), $event, $domain);
    }

    protected function assertRegistrationDomain(EventRegistration $registration, EventStaffDomain $domain): void
    {
        $registration->loadMissing('event');
        if ($registration->event === null) {
            abort(404);
        }

        $this->assertEventDomain($registration->event, $domain);
    }
}
