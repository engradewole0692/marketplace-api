<?php

declare(strict_types=1);

namespace App\Modules\Events\Models;

use App\Models\User;
use App\Modules\Events\Enums\EventAuditEventType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventAuditLog extends Model
{
  /**
   * @var list<string>
   */
  protected $fillable = [
    'event_type',
    'event_id',
    'actor_id',
    'subject_type',
    'subject_id',
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
      'event_type' => EventAuditEventType::class,
      'old_values' => 'array',
      'new_values' => 'array',
      'metadata' => 'array',
    ];
  }

  public function event(): BelongsTo
  {
    return $this->belongsTo(Event::class);
  }

  public function actor(): BelongsTo
  {
    return $this->belongsTo(User::class, 'actor_id');
  }
}
