<?php

declare(strict_types=1);

namespace App\Modules\Counselling\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

trait HasCounsellingUuid
{
  protected static function bootHasCounsellingUuid(): void
  {
    static::creating(function (Model $model): void {
      if (empty($model->getAttribute('uuid'))) {
        $model->setAttribute('uuid', (string) Str::uuid());
      }
    });
  }
}
