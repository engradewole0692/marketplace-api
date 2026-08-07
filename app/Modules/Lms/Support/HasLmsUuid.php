<?php

declare(strict_types=1);

namespace App\Modules\Lms\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

trait HasLmsUuid
{
  protected static function bootHasLmsUuid(): void
  {
    static::creating(function (Model $model): void {
      if (empty($model->getAttribute('uuid'))) {
        $model->setAttribute('uuid', (string) Str::uuid());
      }
    });
  }
}