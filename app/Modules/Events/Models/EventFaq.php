<?php

declare(strict_types=1);

namespace App\Modules\Events\Models;

use App\Modules\Events\Support\HasEventUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventFaq extends Model
{
  use HasEventUuid;

  /**
   * @var list<string>
   */
  protected $fillable = [
    'uuid',
    'event_id',
    'question',
    'answer',
    'sort_order',
    'is_active',
  ];

  /**
   * @return array<string, string>
   */
  protected function casts(): array
  {
    return [
      'sort_order' => 'integer',
      'is_active' => 'boolean',
    ];
  }

  public function scopeActive(Builder $query): Builder
  {
    return $query->where('is_active', true);
  }

  public function event(): BelongsTo
  {
    return $this->belongsTo(Event::class);
  }
}
