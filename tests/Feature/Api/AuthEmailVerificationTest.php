<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\User;
use App\Modules\Communications\Models\CommunicationEmailLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class AuthEmailVerificationTest extends TestCase
{
  use RefreshDatabase;

  public function test_email_verification_works_with_signed_url(): void
  {
    $user = User::factory()->unverified()->create();

    $url = URL::temporarySignedRoute(
      'api.v1.auth.verification.verify',
      now()->addHour(),
      ['id' => $user->id, 'hash' => sha1($user->getEmailForVerification())],
    );

    $response = $this->getJson($url);

    $response
      ->assertOk()
      ->assertJsonPath('success', true);

    $user->refresh();
    $this->assertTrue($user->hasVerifiedEmail());
  }

  public function test_resend_verification_sends_notification(): void
  {
    Mail::fake();

    $user = User::factory()->unverified()->create();
    Sanctum::actingAs($user);

    $response = $this->postJson('/api/v1/auth/email/verification-notification');

    $response
      ->assertOk()
      ->assertJsonPath('success', true);

    $this->assertTrue(
      CommunicationEmailLog::query()
        ->where('event_key', 'auth.email.verification')
        ->where('recipient_email', $user->email)
        ->exists()
    );
  }
}
