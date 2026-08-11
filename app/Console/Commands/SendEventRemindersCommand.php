<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Events\Enums\EventStatus;
use App\Modules\Events\Enums\RegistrationStatus;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Models\EventRegistration;
use App\Modules\Events\Services\NotificationService;
use Illuminate\Console\Command;

/**
 * Sends event reminder emails via the Communications module.
 *
 * Forge requirement: ensure `php artisan schedule:run` runs every minute
 * (Laravel Forge → Scheduler → enable scheduler on the server).
 */
final class SendEventRemindersCommand extends Command
{
  protected $signature = 'events:send-reminders {--hours=24 : Hours before event start to send reminder}';

  protected $description = 'Send idempotent event reminder emails to confirmed registrants';

  public function handle(NotificationService $notifications): int
  {
    $hours = max(1, (int) $this->option('hours'));
    $windowStart = now();
    $windowEnd = now()->addHours($hours);

    $events = Event::query()
      ->where('status', EventStatus::Published->value)
      ->whereNotNull('starts_at')
      ->whereBetween('starts_at', [$windowStart, $windowEnd])
      ->get();

    $sent = 0;
    foreach ($events as $event) {
      $registrations = EventRegistration::query()
        ->where('event_id', $event->id)
        ->whereIn('status', [
          RegistrationStatus::Approved->value,
          RegistrationStatus::CheckedIn->value,
        ])
        ->with(['event.venue', 'member'])
        ->cursor();

      foreach ($registrations as $registration) {
        try {
          $notifications->sendEventReminder($registration, $hours);
          $sent++;
        } catch (\Throwable $exception) {
          report($exception);
        }
      }
    }

    $this->info("Processed event reminders (window: {$hours}h). Attempted: {$sent}.");

    return self::SUCCESS;
  }
}
