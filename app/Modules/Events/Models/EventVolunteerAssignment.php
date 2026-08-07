<?php

declare(strict_types=1);

namespace App\Modules\Events\Models;

use App\Models\Member;
use App\Models\User;
use App\Modules\Events\Enums\VolunteerAssignmentStatus;
use App\Modules\Events\Support\HasEventUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventVolunteerAssignment extends Model
{
  use HasEventUuid;

  /**
   * @var list<string>
   */
  protected $fillable = [
    'uuid',
    'event_id',
    'registration_id',
    'member_id',
    'role_id',
    'status',
    'shift_starts_at',
    'shift_ends_at',
    'notes',
    'performance_score',
    'completed_at',
    'assigned_by_user_id',
  ];

  /**
   * @return array<string, string>
   */
  protected function casts(): array
  {
    return [
      'status' => VolunteerAssignmentStatus::class,
      'shift_starts_at' => 'datetime',
      'shift_ends_at' => 'datetime',
      'completed_at' => 'datetime',
      'performance_score' => 'integer',
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

  public function registration(): BelongsTo
  {
    return $this->belongsTo(EventRegistration::class, 'registration_id');
  }

  public function member(): BelongsTo
  {
    return $this->belongsTo(Member::class);
  }

  public function role(): BelongsTo
  {
    return $this->belongsTo(EventVolunteerRole::class, 'role_id');
  }

  public function assignedBy(): BelongsTo
  {
    return $this->belongsTo(User::class, 'assigned_by_user_id');
  }
}
