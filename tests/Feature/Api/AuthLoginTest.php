<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\UserStatus;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SuperAdminSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class AuthLoginTest extends TestCase
{
  use RefreshDatabase;

  protected function setUp(): void
  {
    parent::setUp();
    $this->seed(RoleSeeder::class);
  }

  public function test_login_succeeds_with_valid_credentials(): void
  {
    $user = User::factory()->create([
      'email' => 'member@example.com',
      'password' => Hash::make('Password123!@#'),
    ]);

    $response = $this->postJson('/api/v1/auth/login', [
      'email' => 'member@example.com',
      'password' => 'Password123!@#',
      'remember' => true,
    ]);

    $response
      ->assertOk()
      ->assertJsonPath('success', true)
      ->assertJsonPath('data.user.email', $user->email);

    $user->refresh();
    $this->assertNotNull($user->last_login_at);
    $this->assertNotNull($user->last_login_ip);
  }

  public function test_invalid_login_returns_standardized_unauthorized_response(): void
  {
    User::factory()->create([
      'email' => 'member@example.com',
      'password' => Hash::make('Password123!@#'),
    ]);

    $response = $this->postJson('/api/v1/auth/login', [
      'email' => 'member@example.com',
      'password' => 'wrong-password',
    ]);

    $response
      ->assertUnauthorized()
      ->assertJsonPath('success', false)
      ->assertJsonPath('code', 'UNAUTHORIZED');
  }

  public function test_suspended_user_cannot_login(): void
  {
    User::factory()->suspended()->create([
      'email' => 'suspended@example.com',
      'password' => Hash::make('Password123!@#'),
    ]);

    $response = $this->postJson('/api/v1/auth/login', [
      'email' => 'suspended@example.com',
      'password' => 'Password123!@#',
    ]);

    $response
      ->assertForbidden()
      ->assertJsonPath('code', 'FORBIDDEN');
  }

  public function test_login_is_rate_limited(): void
  {
    User::factory()->create([
      'email' => 'throttle@example.com',
      'password' => Hash::make('Password123!@#'),
    ]);

    for ($i = 0; $i < 5; $i++) {
      $this->postJson('/api/v1/auth/login', [
        'email' => 'throttle@example.com',
        'password' => 'wrong-password',
      ]);
    }

    $response = $this->postJson('/api/v1/auth/login', [
      'email' => 'throttle@example.com',
      'password' => 'wrong-password',
    ]);

    $response
      ->assertStatus(429)
      ->assertJsonPath('code', 'TOO_MANY_REQUESTS');
  }
}
