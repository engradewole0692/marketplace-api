<?php

declare(strict_types=1);

namespace Tests\Feature\Iam;

use App\Models\User;
use App\Services\Iam\AuthorizationService;
use Laravel\Sanctum\Sanctum;

final class IamAuthorizationTest extends IamTestCase
{
  public function test_user_has_permission_via_role(): void
  {
    $service = app(AuthorizationService::class);

    $this->assertTrue($service->userHasPermission($this->admin, 'users.view'));
    $this->assertTrue($service->userHasPermission($this->admin, 'admin.access'));
  }

  public function test_member_lacks_admin_permissions(): void
  {
    $member = $this->memberUser();
    $service = app(AuthorizationService::class);

    $this->assertFalse($service->userHasPermission($member, 'users.create'));
    $this->assertFalse($service->userHasPermission($member, 'admin.access'));
  }

  public function test_me_endpoint_returns_permissions(): void
  {
    $response = $this->getJson('/api/v1/auth/me');

    $response
      ->assertOk()
      ->assertJsonPath('data.user.id', $this->admin->id)
      ->assertJsonStructure(['data' => ['permissions']]);

    $this->assertContains('admin.access', $response->json('data.permissions'));
  }

  public function test_audit_logs_require_permission(): void
  {
    Sanctum::actingAs($this->memberUser());

    $this->getJson('/api/v1/iam/audit-logs')->assertForbidden();
  }

  public function test_super_admin_can_view_audit_logs(): void
  {
    User::factory()->create();

    $this->getJson('/api/v1/iam/audit-logs')
      ->assertOk()
      ->assertJsonStructure(['data' => ['data', 'meta']]);
  }
}
