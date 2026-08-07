<?php

declare(strict_types=1);

namespace App\Services\Health;

use App\Contracts\ServiceContract;
use App\DTOs\Health\HealthStatusData;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

final class HealthCheckService implements ServiceContract
{
  public function getStatus(): HealthStatusData
  {
    $checks = [
      'database' => $this->checkDatabase(),
      'cache' => $this->checkCache(),
    ];

    $status = collect($checks)->every(fn (array $check): bool => $check['status'] === 'ok')
      ? 'ok'
      : 'degraded';

    return new HealthStatusData(
      status: $status,
      application: (string) config('app.name'),
      environment: (string) config('app.env'),
      version: (string) config('app.version', '1.0.0'),
      checks: $checks,
    );
  }

  /**
   * @return array{status: string, message?: string}
   */
  private function checkDatabase(): array
  {
    try {
      DB::connection()->getPdo();

      return ['status' => 'ok'];
    } catch (Throwable $exception) {
      return [
        'status' => 'error',
        'message' => config('app.debug') ? $exception->getMessage() : 'Database connection failed.',
      ];
    }
  }

  /**
   * @return array{status: string, message?: string}
   */
  private function checkCache(): array
  {
    try {
      $key = 'health_check_'.uniqid('', true);
      Cache::put($key, true, 10);
      $ok = Cache::get($key) === true;
      Cache::forget($key);

      return $ok
        ? ['status' => 'ok']
        : ['status' => 'error', 'message' => 'Cache read/write failed.'];
    } catch (Throwable $exception) {
      return [
        'status' => 'error',
        'message' => config('app.debug') ? $exception->getMessage() : 'Cache connection failed.',
      ];
    }
  }
}
