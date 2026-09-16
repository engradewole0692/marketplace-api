<?php

declare(strict_types=1);

namespace App\Modules\Events\Models;

use App\Models\User;
use App\Modules\Events\Support\HasEventUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class EventSession extends Model
{
  use HasEventUuid;
  use SoftDeletes;

  /**
   * @var list<string>
   */
  protected $fillable = [
    'uuid',
    'event_id',
    'speaker_id',
    'title',
    'session_type',
    'description',
    'starts_at',
    'ends_at',
    'location',
    'capacity',
    'sort_order',
    'session_number',
    'is_active',
    'grace_before_minutes',
    'grace_after_minutes',
    'metadata',
    'track',
    'room',
    'moderator_user_id',
    'resources_json',
  ];

  /**
   * @return array<string, string>
   */
  protected function casts(): array
  {
    return [
      'starts_at' => 'datetime',
      'ends_at' => 'datetime',
      'capacity' => 'integer',
      'sort_order' => 'integer',
      'session_number' => 'integer',
      'is_active' => 'boolean',
      'grace_before_minutes' => 'integer',
      'grace_after_minutes' => 'integer',
      'metadata' => 'array',
      'resources_json' => 'array',
    ];
  }

  public function moderator(): BelongsTo
  {
    return $this->belongsTo(User::class, 'moderator_user_id');
  }

  public function event(): BelongsTo
  {
    return $this->belongsTo(Event::class);
  }

  public function speaker(): BelongsTo
  {
    return $this->belongsTo(Speaker::class);
  }

  public function checkIns(): HasMany
  {
    return $this->hasMany(EventCheckIn::class);
  }

  public function attendanceHistories(): HasMany
  {
    return $this->hasMany(EventAttendanceHistory::class);
  }

  public function sessionAttendances(): HasMany
  {
    return $this->hasMany(EventSessionAttendance::class);
  }

  public function plannedRegistrations(): BelongsToMany
  {
    return $this->belongsToMany(EventRegistration::class, 'event_registration_sessions');
  }
}
