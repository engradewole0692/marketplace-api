<?php

declare(strict_types=1);

namespace App\Modules\Events\Models;

use App\Models\User;
use App\Modules\Events\Support\HasEventUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventStaffAssignment extends Model
{
  use HasEventUuid;

  /**
   * @var list<string>
   */
  protected $fillable = [
    'uuid',
    'event_id',
    'user_id',
    'staff_role',
    'is_active',
    'created_by_user_id',
  ];

  /**
   * @return array<string, string>
   */
  protected function casts(): array
  {
    return [
      'is_active' => 'boolean',
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

  public function user(): BelongsTo
  {
    return $this->belongsTo(User::class);
  }

  public function createdBy(): BelongsTo
  {
    return $this->belongsTo(User::class, 'created_by_user_id');
  }

  public function scopeActive(Builder $query): Builder
  {
    return $query->where('is_active', true);
  }
}
