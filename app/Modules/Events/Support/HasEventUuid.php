<?php

declare(strict_types=1);

namespace App\Modules\Events\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

trait HasEventUuid
{
  protected static function bootHasEventUuid(): void
  {
    static::creating(function (Model $model): void {
      if (empty($model->getAttribute('uuid'))) {
        $model->setAttribute('uuid', (string) Str::uuid());
      }
    });
  }
}
