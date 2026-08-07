<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class AuthSessionTest extends TestCase
{
  use RefreshDatabase;

  public function test_me_endpoint_returns_authenticated_user(): void
  {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $response = $this->getJson('/api/v1/auth/me');

    $response
      ->assertOk()
      ->assertJsonPath('success', true)
      ->assertJsonPath('data.user.uuid', $user->uuid)
      ->assertJsonPath('data.user.email', $user->email);
  }

  public function test_protected_endpoints_reject_unauthenticated_users(): void
  {
    $response = $this->getJson('/api/v1/auth/me');

    $response
      ->assertUnauthorized()
      ->assertJsonPath('success', false)
      ->assertJsonPath('code', 'UNAUTHORIZED');
  }

  public function test_logout_succeeds_for_authenticated_user(): void
  {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $response = $this->postJson('/api/v1/auth/logout');

    $response
      ->assertOk()
      ->assertJsonPath('success', true)
      ->assertJsonPath('message', 'Logout successful.');
  }
}
