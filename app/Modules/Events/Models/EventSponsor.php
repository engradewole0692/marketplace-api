<?php

declare(strict_types=1);

namespace App\Modules\Events\Models;

use App\Modules\Cms\Models\CmsMedia;
use App\Modules\Events\Support\HasEventUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class EventSponsor extends Model
{
  use HasEventUuid;
  use SoftDeletes;

  /**
   * @var list<string>
   */
  protected $fillable = [
    'uuid',
    'event_id',
    'name',
    'slug',
    'logo_media_id',
    'website_url',
    'description',
    'sort_order',
    'metadata',
  ];

  /**
   * @var list<string>
   */
  protected $appends = [
    'logo_url',
  ];

  /**
   * @return array<string, string>
   */
  protected function casts(): array
  {
    return [
      'sort_order' => 'integer',
      'metadata' => 'array',
    ];
  }

  public function event(): BelongsTo
  {
    return $this->belongsTo(Event::class);
  }

  public function logo(): BelongsTo
  {
    return $this->belongsTo(CmsMedia::class, 'logo_media_id');
  }

  public function getLogoUrlAttribute(): ?string
  {
    return $this->logo?->url();
  }
}
