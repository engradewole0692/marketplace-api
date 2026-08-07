<?php

declare(strict_types=1);

namespace App\Modules;

use Illuminate\Support\ServiceProvider;

/**
 * Base service provider for domain modules under app/Modules/{Name}/.
 *
 * Concrete module providers should extend this class and register routes,
 * bindings, policies, and event listeners for their bounded context.
 */
abstract class AbstractModuleServiceProvider extends ServiceProvider
{
  abstract protected function moduleName(): string;

  /**
   * @return class-string[]
   */
  public function provides(): array
  {
    return [];
  }

  public function register(): void
  {
    //
  }

  public function boot(): void
  {
    //
  }

  protected function modulePath(string $path = ''): string
  {
    $base = app_path('Modules/'.$this->moduleName());

    return $path === '' ? $base : $base.'/'.ltrim($path, '/');
  }
}
