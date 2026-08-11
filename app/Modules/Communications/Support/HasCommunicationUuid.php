<?php

declare(strict_types=1);

namespace App\Modules\Communications\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

trait HasCommunicationUuid
{
  protected static function bootHasCommunicationUuid(): void
  {
    static::creating(function (Model $model): void {
      if (empty($model->getAttribute('uuid'))) {
        $model->setAttribute('uuid', (string) Str::uuid());
      }
    });
  }
}
