<?php

declare(strict_types=1);

namespace App\Modules\Events\Models;

use App\Modules\Events\Support\HasEventUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class EventRegistrationQuestion extends Model
{
  use HasEventUuid;
  use SoftDeletes;

  /**
   * @var list<string>
   */
  protected $fillable = [
    'uuid',
    'event_id',
    'field_key',
    'question',
    'help_text',
    'answer_type',
    'options',
    'is_enabled',
    'is_required',
    'maps_to_member_field',
    'sort_order',
    'metadata',
  ];

  /**
   * @return array<string, string>
   */
  protected function casts(): array
  {
    return [
      'options' => 'array',
      'is_enabled' => 'boolean',
      'is_required' => 'boolean',
      'sort_order' => 'integer',
      'metadata' => 'array',
    ];
  }

  public function getRouteKeyName(): string
  {
    return 'uuid';
  }

  public function scopeEnabled(Builder $query): Builder
  {
    return $query->where('is_enabled', true);
  }

  public function event(): BelongsTo
  {
    return $this->belongsTo(Event::class);
  }

  public function answers(): HasMany
  {
    return $this->hasMany(EventRegistrationQuestionAnswer::class, 'question_id');
  }
}
