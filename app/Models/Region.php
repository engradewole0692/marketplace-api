<?php

declare(strict_types=1);

namespace App\Models;

use App\Modules\Cms\Models\CmsCountry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Region extends Model
{
  use SoftDeletes;

  /**
   * @var list<string>
   */
  protected $fillable = [
    'uuid',
    'name',
    'slug',
    'country_id',
    'is_active',
    'sort_order',
  ];

  protected static function booted(): void
  {
    static::creating(function (Region $region): void {
      if (empty($region->uuid)) {
        $region->uuid = (string) Str::uuid();
      }
    });
  }

  /**
   * @return array<string, string>
   */
  protected function casts(): array
  {
    return [
      'is_active' => 'boolean',
    ];
  }

  public function country(): BelongsTo
  {
    return $this->belongsTo(CmsCountry::class, 'country_id');
  }
}
