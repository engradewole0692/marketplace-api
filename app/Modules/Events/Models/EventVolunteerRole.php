<?php

declare(strict_types=1);

namespace App\Modules\Events\Models;

use App\Modules\Events\Support\HasEventUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EventVolunteerRole extends Model
{
  use HasEventUuid;

  /**
   * @var list<string>
   */
  protected $fillable = [
    'uuid',
    'event_id',
    'name',
    'slug',
    'description',
    'slots',
    'is_active',
    'sort_order',
  ];

  /**
   * @return array<string, string>
   */
  protected function casts(): array
  {
    return [
      'is_active' => 'boolean',
      'slots' => 'integer',
      'sort_order' => 'integer',
    ];
  }

  public function getRouteKeyName(): string
  {
    return 'uuid';
  }

  public function event(): BelongsTo
  {
    return $this->belongsTo(Event::class);
  }

  public function assignments(): HasMany
  {
    return $this->hasMany(EventVolunteerAssignment::class, 'role_id');
  }
}
