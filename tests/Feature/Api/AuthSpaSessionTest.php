<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Verifies Sanctum SPA cookie sessions for supported local frontend origins.
 * Covers login → me → dashboard → logout → invalid session regressions.
 */
final class AuthSpaSessionTest extends TestCase
{
  use RefreshDatabase;

  protected function setUp(): void
  {
    parent::setUp();
    $this->seed([
      RoleSeeder::class,
      PermissionSeeder::class,
      RolePermissionSeeder::class,
    ]);
    // SPA cookie sessions require credentials on JSON requests.
    $this->withCredentials();
  }

  /**
   * @return array<string, array{0: string}>
   */
  public static function spaOriginProvider(): array
  {
    return [
      'localhost:8081' => ['http://localhost:8081'],
      '127.0.0.1:8081' => ['http://127.0.0.1:8081'],
      'localhost:5173' => ['http://localhost:5173'],
      '127.0.0.1:5173' => ['http://127.0.0.1:5173'],
    ];
  }

  #[DataProvider('spaOriginProvider')]
  public function test_spa_login_establishes_session_for_me_endpoint(string $spaOrigin): void
  {
    $user = $this->createAdminUser('spa@example.com');

    $spaHeaders = $this->spaHeaders($spaOrigin);
    $xsrfToken = $this->bootstrapCsrf($spaHeaders);

    $login = $this->postJson('/api/v1/auth/login', [
      'email' => 'spa@example.com',
      'password' => 'Password123!@#',
    ], array_merge($spaHeaders, ['X-XSRF-TOKEN' => $xsrfToken]));

    $login
      ->assertOk()
      ->assertJsonPath('success', true)
      ->assertJsonPath('data.user.email', $user->email);

    $this->assertNotNull(
      $this->sessionCookieFromResponse($login),
      'Session cookie was not set after login',
    );

    $me = $this->getJson('/api/v1/auth/me', $spaHeaders);

    $me
      ->assertOk()
      ->assertJsonPath('success', true)
      ->assertJsonPath('data.user.email', $user->email)
      ->assertJsonPath('data.permissions', fn ($permissions) => in_array('admin.access', $permissions, true));
  }

  public function test_me_works_for_xmlhttprequest_without_referer_or_origin(): void
  {
    $user = $this->createAdminUser('spa-no-ref@example.com');

    $spaHeaders = $this->spaHeaders('http://localhost:8081');
    $xsrfToken = $this->bootstrapCsrf($spaHeaders);

    $this->postJson('/api/v1/auth/login', [
      'email' => 'spa-no-ref@example.com',
      'password' => 'Password123!@#',
    ], array_merge($spaHeaders, ['X-XSRF-TOKEN' => $xsrfToken]))->assertOk();

    $me = $this->getJson('/api/v1/auth/me', [
      'Accept' => 'application/json',
      'X-Requested-With' => 'XMLHttpRequest',
    ]);

    $me
      ->assertOk()
      ->assertJsonPath('success', true)
      ->assertJsonPath('data.user.email', $user->email);
  }

  public function test_session_persists_across_repeated_me_calls(): void
  {
    $user = $this->createAdminUser('persist@example.com');
    $spaHeaders = $this->spaHeaders('http://localhost:8081');
    $xsrfToken = $this->bootstrapCsrf($spaHeaders);

    $this->postJson('/api/v1/auth/login', [
      'email' => 'persist@example.com',
      'password' => 'Password123!@#',
    ], array_merge($spaHeaders, ['X-XSRF-TOKEN' => $xsrfToken]))->assertOk();

    $this->getJson('/api/v1/auth/me', $spaHeaders)
      ->assertOk()
      ->assertJsonPath('data.user.email', $user->email);

    // Simulate browser refresh / AuthProvider remount: another /me with same cookies.
    $this->getJson('/api/v1/auth/me', $spaHeaders)
      ->assertOk()
      ->assertJsonPath('data.user.email', $user->email)
      ->assertJsonPath('data.permissions', fn ($permissions) => in_array('admin.access', $permissions, true));
  }

  public function test_authenticated_session_can_access_dashboard_overview(): void
  {
    $this->createAdminUser('dash@example.com');
    $spaHeaders = $this->spaHeaders('http://localhost:8081');
    $xsrfToken = $this->bootstrapCsrf($spaHeaders);

    $this->postJson('/api/v1/auth/login', [
      'email' => 'dash@example.com',
      'password' => 'Password123!@#',
    ], array_merge($spaHeaders, ['X-XSRF-TOKEN' => $xsrfToken]))->assertOk();

    $this->getJson('/api/v1/dashboard/overview', $spaHeaders)
      ->assertOk()
      ->assertJsonPath('success', true);
  }

  public function test_logout_invalidates_spa_session(): void
  {
    $this->createAdminUser('logout@example.com');
    $spaHeaders = $this->spaHeaders('http://localhost:8081');
    $xsrfToken = $this->bootstrapCsrf($spaHeaders);

    $login = $this->postJson('/api/v1/auth/login', [
      'email' => 'logout@example.com',
      'password' => 'Password123!@#',
    ], array_merge($spaHeaders, ['X-XSRF-TOKEN' => $xsrfToken]));

    $login->assertOk();

    $this->getJson('/api/v1/auth/me', $spaHeaders)->assertOk();

    $logoutXsrf = $this->xsrfTokenFromResponse($login) ?? $xsrfToken;

    $this->postJson('/api/v1/auth/logout', [], array_merge($spaHeaders, [
      'X-XSRF-TOKEN' => $logoutXsrf,
    ]))
      ->assertOk()
      ->assertJsonPath('success', true);

    $this->assertGuest('web');

    // Fresh request cycle after SPA logout (mirrors browser clearing in-memory auth).
    Auth::forgetGuards();
    $this->flushSession();

    $this->getJson('/api/v1/auth/me', $spaHeaders)
      ->assertUnauthorized()
      ->assertJsonPath('success', false)
      ->assertJsonPath('code', 'UNAUTHORIZED');

    $this->getJson('/api/v1/dashboard/overview', $spaHeaders)
      ->assertUnauthorized();
  }

  public function test_invalid_session_is_rejected_before_dashboard(): void
  {
    $spaHeaders = $this->spaHeaders('http://localhost:8081');

    $this->getJson('/api/v1/auth/me', $spaHeaders)
      ->assertUnauthorized();

    $this->getJson('/api/v1/dashboard/overview', $spaHeaders)
      ->assertUnauthorized();
  }

  public function test_login_again_after_logout_restores_session(): void
  {
    $user = $this->createAdminUser('relogin@example.com');
    $spaHeaders = $this->spaHeaders('http://localhost:8081');
    $xsrfToken = $this->bootstrapCsrf($spaHeaders);

    $login = $this->postJson('/api/v1/auth/login', [
      'email' => 'relogin@example.com',
      'password' => 'Password123!@#',
    ], array_merge($spaHeaders, ['X-XSRF-TOKEN' => $xsrfToken]));
    $login->assertOk();

    $logoutXsrf = $this->xsrfTokenFromResponse($login) ?? $xsrfToken;
    $this->postJson('/api/v1/auth/logout', [], array_merge($spaHeaders, [
      'X-XSRF-TOKEN' => $logoutXsrf,
    ]))->assertOk();

    $this->assertGuest('web');

    $csrfAgain = $this->bootstrapCsrf($spaHeaders);
    $this->postJson('/api/v1/auth/login', [
      'email' => 'relogin@example.com',
      'password' => 'Password123!@#',
    ], array_merge($spaHeaders, ['X-XSRF-TOKEN' => $csrfAgain]))->assertOk();

    $this->getJson('/api/v1/auth/me', $spaHeaders)
      ->assertOk()
      ->assertJsonPath('data.user.email', $user->email);

    $this->getJson('/api/v1/dashboard/overview', $spaHeaders)
      ->assertOk();
  }

  /**
   * @param  array<string, string>  $spaHeaders
   */
  private function bootstrapCsrf(array $spaHeaders): string
  {
    $csrfResponse = $this->get('/sanctum/csrf-cookie', $spaHeaders);
    $csrfResponse->assertNoContent();

    $xsrfToken = $this->xsrfTokenFromResponse($csrfResponse);
    $this->assertNotNull($xsrfToken, 'XSRF-TOKEN cookie was not set by /sanctum/csrf-cookie');

    return $xsrfToken;
  }

  /**
   * @return array<string, string>
   */
  private function spaHeaders(string $spaOrigin): array
  {
    return [
      'Origin' => $spaOrigin,
      'Referer' => $spaOrigin.'/admin/login',
      'Accept' => 'application/json',
      'X-Requested-With' => 'XMLHttpRequest',
    ];
  }

  private function createAdminUser(string $email): User
  {
    $user = User::factory()->create([
      'email' => $email,
      'password' => Hash::make('Password123!@#'),
    ]);

    $role = Role::query()->where('slug', 'super_administrator')->firstOrFail();
    $user->roles()->sync([$role->id]);

    return $user;
  }

  private function xsrfTokenFromResponse(\Illuminate\Testing\TestResponse $response): ?string
  {
    foreach ($response->headers->getCookies() as $cookie) {
      if ($cookie->getName() === 'XSRF-TOKEN') {
        return urldecode($cookie->getValue());
      }
    }

    return null;
  }

  private function sessionCookieFromResponse(\Illuminate\Testing\TestResponse $response): ?string
  {
    $sessionName = config('session.cookie');

    foreach ($response->headers->getCookies() as $cookie) {
      if ($cookie->getName() === $sessionName) {
        return $cookie->getValue();
      }
    }

    return null;
  }
}
