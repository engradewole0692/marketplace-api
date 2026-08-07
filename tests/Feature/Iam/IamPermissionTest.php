<?php

declare(strict_types=1);

namespace Tests\Feature\Iam;

use Laravel\Sanctum\Sanctum;

final class IamPermissionTest extends IamTestCase
{
  public function test_permissions_can_be_listed_grouped(): void
  {
    $response = $this->getJson('/api/v1/iam/permissions?grouped=1');

    $response
      ->assertOk()
      ->assertJsonPath('success', true)
      ->assertJsonStructure([
        'data' => [
          'groups' => [
            ['module', 'permissions'],
          ],
        ],
      ]);
  }

  public function test_permissions_index_is_paginated(): void
  {
    $response = $this->getJson('/api/v1/iam/permissions');

    $response
      ->assertOk()
      ->assertJsonStructure(['data' => ['data', 'meta']]);
  }

  public function test_member_cannot_view_permissions(): void
  {
    Sanctum::actingAs($this->memberUser());

    $this->getJson('/api/v1/iam/permissions')->assertForbidden();
  }
}
