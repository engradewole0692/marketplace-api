<?php

declare(strict_types=1);

namespace App\Modules\Cms\Models;

use App\Modules\Cms\Support\HasCmsUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class CmsCountry extends Model
{
  use HasCmsUuid;
  use SoftDeletes;

  protected $table = 'cms_countries';

  protected $fillable = [
    'uuid', 'name', 'slug', 'code', 'region', 'flag_emoji', 'latitude', 'longitude',
    'launched_year', 'summary', 'content', 'hero_media_id', 'is_active', 'sort_order',
    'created_by', 'updated_by',
  ];

  protected function casts(): array
  {
    return [
      'content' => 'array',
      'is_active' => 'boolean',
      'latitude' => 'float',
      'longitude' => 'float',
    ];
  }

  public function getRouteKeyName(): string
  {
    return 'uuid';
  }

  public function leaders(): HasMany
  {
    return $this->hasMany(CmsLeadershipProfile::class, 'country_id');
  }

  public function regions(): HasMany
  {
    return $this->hasMany(\App\Models\Region::class, 'country_id');
  }

  public function heroMedia(): BelongsTo
  {
    return $this->belongsTo(CmsMedia::class, 'hero_media_id');
  }
}
