<?php

declare(strict_types=1);

namespace App\Modules\Lms\Models;

use Illuminate\Database\Eloquent\Model;

class LmsSetting extends Model
{
  protected $table = 'lms_settings';

  /** @var list<string> */
  protected $fillable = ['key', 'value'];

  protected function casts(): array
  {
    return [
      'value' => 'json',
    ];
  }

  /** @return array<string, mixed> */
  public static function defaults(): array
  {
    return [
      'default_currency' => 'USD',
      'allow_public_registration' => true,
      'allow_member_discount' => true,
      'certificate_prefix' => 'MM-LMS',
      'default_completion_threshold' => 100,
      'featured_limit' => 6,
    ];
  }

  /** @return array<string, mixed> */
  public static function defaultsMerged(): array
  {
    $stored = static::query()->pluck('value', 'key')->all();
    $merged = static::defaults();
    foreach ($stored as $key => $value) {
      $merged[$key] = $value;
    }

    return $merged;
  }
}
