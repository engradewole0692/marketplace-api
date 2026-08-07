<?php

declare(strict_types=1);

namespace App\Modules\Events\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Arr;

/**
 * The SPA only ever receives UUIDs from event/venue/speaker/media API
 * resources (never internal numeric primary keys). Write endpoints validate
 * foreign keys against the internal `id` column, so incoming UUID values are
 * resolved to their numeric primary key before validation runs.
 */
final class UuidResolver
{
  /**
   * @param  array<string, class-string<\Illuminate\Database\Eloquent\Model>>  $map  dot-path => model class
   */
  public static function resolve(Request $request, array $map): void
  {
    $data = $request->all();
    $changed = false;

    foreach ($map as $path => $modelClass) {
      $value = Arr::get($data, $path);

      if ($value === null || $value === '' || is_numeric($value)) {
        continue;
      }

      $record = $modelClass::query()->where('uuid', $value)->first();
      if ($record !== null) {
        Arr::set($data, $path, $record->getKey());
        $changed = true;
      }
    }

    if ($changed) {
      $request->merge($data);
    }
  }
}
