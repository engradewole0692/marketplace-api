<?php

declare(strict_types=1);

namespace App\Modules;

final class ModuleRegistry
{
  /**
   * @return list<string>
   */
  public static function all(): array
  {
    /** @var list<string> $modules */
    $modules = config('modules.modules', []);

    return $modules;
  }

  public static function exists(string $name): bool
  {
    return in_array($name, self::all(), true);
  }

  public static function path(string $name, string $subpath = ''): string
  {
    $base = app_path('Modules/'.$name);

    return $subpath === '' ? $base : $base.'/'.ltrim($subpath, '/');
  }
}
