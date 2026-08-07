<?php

declare(strict_types=1);

namespace App\Modules\Events\Models;

use App\Modules\Cms\Models\CmsMedia;
use App\Modules\Events\Support\HasEventUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class EventGalleryItem extends Model
{
  use HasEventUuid;
  use SoftDeletes;

  /**
   * @var list<string>
   */
  protected $fillable = [
    'uuid',
    'event_id',
    'title',
    'caption',
    'media_type',
    'media_id',
    'external_url',
    'alt_text',
    'sort_order',
    'is_featured',
    'metadata',
  ];

  /**
   * @var list<string>
   */
  protected $appends = [
    'media_url',
  ];

  /**
   * @return array<string, string>
   */
  protected function casts(): array
  {
    return [
      'sort_order' => 'integer',
      'is_featured' => 'boolean',
      'metadata' => 'array',
    ];
  }

  public function event(): BelongsTo
  {
    return $this->belongsTo(Event::class);
  }

  public function media(): BelongsTo
  {
    return $this->belongsTo(CmsMedia::class, 'media_id');
  }

  public function getMediaUrlAttribute(): ?string
  {
    return $this->external_url ?: $this->media?->url();
  }
}
