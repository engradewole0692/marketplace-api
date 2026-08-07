<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class HealthEndpointTest extends TestCase
{
  use RefreshDatabase;

  public function test_health_endpoint_returns_successful_api_response(): void
  {
    $response = $this->getJson('/api/v1/health');

    $response
      ->assertOk()
      ->assertJsonStructure([
        'success',
        'data' => [
          'status',
          'application',
          'environment',
          'version',
          'checks' => [
            'database',
            'cache',
          ],
        ],
        'message',
        'meta' => [
          'timestamp',
          'request_id',
        ],
      ])
      ->assertJson([
        'success' => true,
        'data' => [
          'status' => 'ok',
        ],
      ]);
  }
}
