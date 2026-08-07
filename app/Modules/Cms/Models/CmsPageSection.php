<?php

declare(strict_types=1);

namespace App\Modules\Cms\Models;

use App\Modules\Cms\Support\HasCmsUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class CmsPageSection extends Model
{
  use HasCmsUuid;
  use SoftDeletes;

  protected $table = 'cms_page_sections';

  protected $fillable = [
    'uuid',
    'page_id',
    'page_slug',
    'section_key',
    'section_type',
    'title',
    'content',
    'draft_content',
    'is_active',
    'status',
    'sort_order',
    'published_at',
    'created_by',
    'updated_by',
  ];

  protected function casts(): array
  {
    return [
      'content' => 'array',
      'draft_content' => 'array',
      'is_active' => 'boolean',
      'published_at' => 'datetime',
    ];
  }

  public function getRouteKeyName(): string
  {
    return 'uuid';
  }

  public function page(): BelongsTo
  {
    return $this->belongsTo(CmsPage::class, 'page_id');
  }

  public function versions(): HasMany
  {
    return $this->hasMany(CmsPageSectionVersion::class, 'section_id')->orderByDesc('version_number');
  }

  public function editableContent(): array
  {
    return $this->draft_content ?? $this->content ?? [];
  }
}
