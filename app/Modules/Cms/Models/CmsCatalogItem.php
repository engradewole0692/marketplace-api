<?php

declare(strict_types=1);

namespace App\Modules\Cms\Models;

use App\Modules\Cms\Enums\CatalogItemType;
use App\Modules\Cms\Support\HasCmsUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class CmsCatalogItem extends Model
{
  use HasCmsUuid;
  use SoftDeletes;

  protected $table = 'cms_catalog_items';

  protected $fillable = [
    'uuid', 'type', 'title', 'slug', 'summary', 'body', 'metadata', 'category', 'tags',
    'featured_media_id', 'status', 'is_active', 'is_featured', 'sort_order', 'published_at',
    'created_by', 'updated_by',
  ];

  protected function casts(): array
  {
    return [
      'type' => CatalogItemType::class,
      'metadata' => 'array',
      'tags' => 'array',
      'is_active' => 'boolean',
      'is_featured' => 'boolean',
      'published_at' => 'datetime',
    ];
  }

  public function featuredMedia(): BelongsTo
  {
    return $this->belongsTo(CmsMedia::class, 'featured_media_id');
  }

  public function getRouteKeyName(): string
  {
    return 'uuid';
  }
}
