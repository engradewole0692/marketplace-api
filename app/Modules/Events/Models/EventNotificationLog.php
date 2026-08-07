<?php

declare(strict_types=1);

namespace App\Modules\Events\Models;

use App\Models\Member;
use App\Modules\Events\Enums\NotificationStatus;
use App\Modules\Events\Support\HasEventUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventNotificationLog extends Model
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
    'template_id',
    'channel',
    'recipient',
    'subject',
    'status',
    'queued_at',
    'sent_at',
    'failed_at',
    'failure_reason',
    'metadata',
  ];

  /**
   * @return array<string, string>
   */
  protected function casts(): array
  {
    return [
      'status' => NotificationStatus::class,
      'queued_at' => 'datetime',
      'sent_at' => 'datetime',
      'failed_at' => 'datetime',
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

  public function template(): BelongsTo
  {
    return $this->belongsTo(EventNotificationTemplate::class, 'template_id');
  }
}
