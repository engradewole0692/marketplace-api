<?php

declare(strict_types=1);

namespace App\Modules\Events\Models;

use App\Models\Member;
use App\Models\User;
use App\Modules\Events\Enums\CheckInMethod;
use App\Modules\Events\Support\HasEventUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventCheckIn extends Model
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
    'checked_in_by_user_id',
    'method',
    'checked_in_at',
    'notes',
    'metadata',
  ];

  /**
   * @return array<string, string>
   */
  protected function casts(): array
  {
    return [
      'method' => CheckInMethod::class,
      'checked_in_at' => 'datetime',
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

  public function checkedInBy(): BelongsTo
  {
    return $this->belongsTo(User::class, 'checked_in_by_user_id');
  }
}
