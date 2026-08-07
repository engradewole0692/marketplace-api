<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class AuthPasswordTest extends TestCase
{
  use RefreshDatabase;

  public function test_forgot_password_sends_reset_link(): void
  {
    Notification::fake();

    $user = User::factory()->create(['email' => 'reset@example.com']);

    $response = $this->postJson('/api/v1/auth/forgot-password', [
      'email' => 'reset@example.com',
    ]);

    $response
      ->assertOk()
      ->assertJsonPath('success', true);

    Notification::assertSentTo($user, ResetPassword::class);
  }

  public function test_password_reset_works_with_valid_token(): void
  {
    $user = User::factory()->create(['email' => 'reset@example.com']);
    $token = Password::createToken($user);

    $response = $this->postJson('/api/v1/auth/reset-password', [
      'email' => 'reset@example.com',
      'token' => $token,
      'password' => 'NewPassword123!@#',
      'password_confirmation' => 'NewPassword123!@#',
    ]);

    $response
      ->assertOk()
      ->assertJsonPath('success', true);

    $user->refresh();
    $this->assertTrue(Hash::check('NewPassword123!@#', $user->password));
  }

  public function test_change_password_requires_authentication(): void
  {
    $response = $this->postJson('/api/v1/auth/change-password', [
      'current_password' => 'Password123!@#',
      'password' => 'NewPassword123!@#',
      'password_confirmation' => 'NewPassword123!@#',
    ]);

    $response->assertUnauthorized();
  }

  public function test_authenticated_user_can_change_password(): void
  {
    $user = User::factory()->create([
      'password' => Hash::make('Password123!@#'),
    ]);
    Sanctum::actingAs($user);

    $response = $this->postJson('/api/v1/auth/change-password', [
      'current_password' => 'Password123!@#',
      'password' => 'AnotherPass123!@#',
      'password_confirmation' => 'AnotherPass123!@#',
    ]);

    $response
      ->assertOk()
      ->assertJsonPath('success', true);

    $user->refresh();
    $this->assertTrue(Hash::check('AnotherPass123!@#', $user->password));
  }

  public function test_validation_errors_use_standardized_format(): void
  {
    $response = $this->postJson('/api/v1/auth/reset-password', [
      'email' => 'not-an-email',
      'token' => '',
      'password' => 'short',
      'password_confirmation' => 'mismatch',
    ]);

    $response
      ->assertUnprocessable()
      ->assertJsonPath('success', false)
      ->assertJsonPath('code', 'VALIDATION_FAILED')
      ->assertJsonStructure(['errors']);
  }
}
