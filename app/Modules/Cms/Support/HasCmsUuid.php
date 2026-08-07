<?php

declare(strict_types=1);

namespace App\Modules\Cms\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

trait HasCmsUuid
{
  protected static function bootHasCmsUuid(): void
  {
    static::creating(function (Model $model): void {
      if (empty($model->getAttribute('uuid'))) {
        $model->setAttribute('uuid', (string) Str::uuid());
      }
    });
  }
}
