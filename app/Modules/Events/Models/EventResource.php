<?php

declare(strict_types=1);

namespace App\Modules\Events\Models;

use App\Modules\Cms\Models\CmsMedia;
use App\Modules\Events\Support\HasEventUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class EventResource extends Model
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
    'resource_type',
    'description',
    'media_id',
    'external_url',
    'is_public',
    'sort_order',
    'metadata',
  ];

  /**
   * @var list<string>
   */
  protected $appends = [
    'resource_url',
  ];

  /**
   * @return array<string, string>
   */
  protected function casts(): array
  {
    return [
      'is_public' => 'boolean',
      'sort_order' => 'integer',
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

  public function getResourceUrlAttribute(): ?string
  {
    return $this->external_url ?: $this->media?->url();
  }
}
