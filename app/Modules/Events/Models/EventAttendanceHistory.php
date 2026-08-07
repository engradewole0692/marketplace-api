<?php

declare(strict_types=1);

namespace App\Modules\Events\Models;

use App\Models\Member;
use App\Models\User;
use App\Modules\Events\Enums\AttendanceStatus;
use App\Modules\Events\Support\HasEventUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventAttendanceHistory extends Model
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
    'event_session_id',
    'status',
    'source',
    'occurred_at',
    'recorded_by_user_id',
    'notes',
    'metadata',
  ];

  /**
   * @return array<string, string>
   */
  protected function casts(): array
  {
    return [
      'status' => AttendanceStatus::class,
      'occurred_at' => 'datetime',
      'metadata' => 'array',
    ];
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

  public function session(): BelongsTo
  {
    return $this->belongsTo(EventSession::class, 'event_session_id');
  }

  public function recorder(): BelongsTo
  {
    return $this->belongsTo(User::class, 'recorded_by_user_id');
  }
}
