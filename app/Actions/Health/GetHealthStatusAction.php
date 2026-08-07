<?php

declare(strict_types=1);

namespace App\Actions\Health;

use App\DTOs\Health\HealthStatusData;
use App\Services\Health\HealthCheckService;

final readonly class GetHealthStatusAction
{
  public function __construct(
    private HealthCheckService $healthCheckService,
  ) {}

  public function execute(): HealthStatusData
  {
    return $this->healthCheckService->getStatus();
  }
}
