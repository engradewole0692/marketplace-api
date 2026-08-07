<?php

declare(strict_types=1);

namespace App\Modules\Cms\Models;

use App\Modules\Cms\Enums\PageStatus;
use App\Modules\Cms\Support\HasCmsUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class CmsPage extends Model
{
  use HasCmsUuid;
  use SoftDeletes;

  protected $table = 'cms_pages';

  protected $fillable = [
    'uuid', 'title', 'slug', 'status', 'hero_title', 'hero_subtitle',
    'hero_media_id', 'blocks', 'sort_order', 'published_at', 'scheduled_at', 'created_by', 'updated_by',
  ];

  protected function casts(): array
  {
    return [
      'status' => PageStatus::class,
      'blocks' => 'array',
      'published_at' => 'datetime',
      'scheduled_at' => 'datetime',
    ];
  }

  public function sections(): HasMany
  {
    return $this->hasMany(CmsPageSection::class, 'page_id');
  }

  public function versions(): HasMany
  {
    return $this->hasMany(CmsPageVersion::class, 'page_id');
  }

  public function heroMedia(): BelongsTo
  {
    return $this->belongsTo(CmsMedia::class, 'hero_media_id');
  }

  public function getRouteKeyName(): string
  {
    return 'uuid';
  }
}
