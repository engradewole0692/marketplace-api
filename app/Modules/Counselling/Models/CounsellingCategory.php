<?php

declare(strict_types=1);

namespace App\Modules\Counselling\Models;

use App\Modules\Counselling\Support\HasCounsellingUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class CounsellingCategory extends Model
{
  use HasCounsellingUuid;
  use SoftDeletes;

  protected $table = 'counselling_categories';

  /** @var list<string> */
  protected $fillable = [
    'uuid',
    'name',
    'slug',
    'description',
    'icon',
    'sort_order',
    'is_visible',
    'status',
    'seo_title',
    'seo_description',
  ];

  protected function casts(): array
  {
    return [
      'sort_order' => 'integer',
      'is_visible' => 'boolean',
    ];
  }

  public function getRouteKeyName(): string
  {
    return 'uuid';
  }

  public function services(): HasMany
  {
    return $this->hasMany(CounsellingService::class, 'category_id')->orderBy('sort_order');
  }

  public function cases(): HasMany
  {
    return $this->hasMany(CounsellingCase::class, 'category_id');
  }
}
