<?php

declare(strict_types=1);

namespace App\Modules\Events\Models;

use App\Models\Member;
use App\Models\User;
use App\Modules\Events\Enums\RegistrationAuditEventType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventRegistrationAuditLog extends Model
{
  /**
   * @var list<string>
   */
  protected $fillable = [
    'event_type',
    'registration_id',
    'event_id',
    'member_id',
    'actor_id',
    'old_values',
    'new_values',
    'metadata',
    'ip_address',
    'user_agent',
  ];

  /**
   * @return array<string, string>
   */
  protected function casts(): array
  {
    return [
      'event_type' => RegistrationAuditEventType::class,
      'old_values' => 'array',
      'new_values' => 'array',
      'metadata' => 'array',
    ];
  }

  public function registration(): BelongsTo
  {
    return $this->belongsTo(EventRegistration::class, 'registration_id');
  }

  public function event(): BelongsTo
  {
    return $this->belongsTo(Event::class);
  }

  public function member(): BelongsTo
  {
    return $this->belongsTo(Member::class);
  }

  public function actor(): BelongsTo
  {
    return $this->belongsTo(User::class, 'actor_id');
  }
}
