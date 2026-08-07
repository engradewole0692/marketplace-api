<?php

declare(strict_types=1);

namespace App\Modules\Donations\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

trait HasDonationUuid
{
  protected static function bootHasDonationUuid(): void
  {
    static::creating(function (Model $model): void {
      if (empty($model->getAttribute('uuid'))) {
        $model->setAttribute('uuid', (string) Str::uuid());
      }
    });
  }
}
