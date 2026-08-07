<?php

declare(strict_types=1);

namespace App\Helpers;

final class ApiHelper
{
  public static function versionedPath(string $path = ''): string
  {
    $prefix = trim((string) config('api.prefix'), '/');
    $version = trim((string) config('api.version'), '/');
    $base = "{$prefix}/{$version}";

    if ($path === '') {
      return '/'.$base;
    }

    return '/'.$base.'/'.ltrim($path, '/');
  }
}
