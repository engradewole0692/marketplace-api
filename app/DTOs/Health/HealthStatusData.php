<?php

declare(strict_types=1);

namespace App\DTOs\Health;

use Illuminate\Contracts\Support\Arrayable;

/**
 * @implements Arrayable<string, mixed>
 */
final readonly class HealthStatusData implements Arrayable
{
  /**
   * @param  array<string, mixed>  $checks
   */
  public function __construct(
    public string $status,
    public string $application,
    public string $environment,
    public string $version,
    public array $checks,
  ) {}

  /**
   * @return array<string, mixed>
   */
  public function toArray(): array
  {
    return [
      'status' => $this->status,
      'application' => $this->application,
      'environment' => $this->environment,
      'version' => $this->version,
      'checks' => $this->checks,
    ];
  }
}
