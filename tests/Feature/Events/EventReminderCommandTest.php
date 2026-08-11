<?php

declare(strict_types=1);

namespace Tests\Feature\Events;

use App\Modules\Communications\Models\CommunicationEmailLog;
use App\Modules\Events\Enums\EventStatus;
use App\Modules\Events\Enums\EventVisibility;
use App\Modules\Events\Enums\RegistrationStatus;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Models\EventRegistration;
use Database\Seeders\CommunicationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

final class EventReminderCommandTest extends TestCase
{
  use RefreshDatabase;

  protected function setUp(): void
  {
    parent::setUp();
    $this->seed([CommunicationSeeder::class]);
  }

  public function test_send_reminders_dispatches_idempotent_communication_for_upcoming_events(): void
  {
    Mail::fake();

    $event = Event::query()->create([
      'title' => 'Tomorrow Gathering',
      'slug' => 'tomorrow-gathering',
      'starts_at' => now()->addHours(12),
      'ends_at' => now()->addHours(14),
      'visibility' => EventVisibility::Public,
      'status' => EventStatus::Published,
      'published_at' => now(),
      'capacity' => 100,
    ]);

    $registration = EventRegistration::query()->create([
      'event_id' => $event->id,
      'registration_number' => 'EVT-'.$event->id.'-000001',
      'guest_name' => 'Reminder Guest',
      'guest_email' => 'reminder-guest@example.com',
      'status' => RegistrationStatus::Approved,
      'consent_accepted' => true,
      'approved_at' => now(),
    ]);

    Artisan::call('events:send-reminders', ['--hours' => 24]);
    Artisan::call('events:send-reminders', ['--hours' => 24]);

    $this->assertSame(
      1,
      CommunicationEmailLog::query()
        ->where('event_key', 'event.reminder')
        ->where('recipient_email', 'reminder-guest@example.com')
        ->count(),
    );

    Mail::assertSent(\App\Modules\Communications\Mail\CommunicationMailable::class, 1);

    $this->assertNotNull($registration->uuid);
  }

  public function test_send_reminders_skips_events_outside_window(): void
  {
    Mail::fake();

    $event = Event::query()->create([
      'title' => 'Far Future Event',
      'slug' => 'far-future-event',
      'starts_at' => now()->addDays(10),
      'visibility' => EventVisibility::Public,
      'status' => EventStatus::Published,
      'published_at' => now(),
    ]);

    EventRegistration::query()->create([
      'event_id' => $event->id,
      'registration_number' => 'EVT-'.$event->id.'-000002',
      'guest_name' => 'Future Guest',
      'guest_email' => 'future@example.com',
      'status' => RegistrationStatus::Approved,
      'consent_accepted' => true,
    ]);

    Artisan::call('events:send-reminders', ['--hours' => 24]);

    $this->assertSame(0, CommunicationEmailLog::query()->where('event_key', 'event.reminder')->count());
    Mail::assertNothingSent();
  }
}
