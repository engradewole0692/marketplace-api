<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class MemberNotificationQueue extends Model
{
  protected $table = 'member_notification_queue';

  protected $fillable = [
    'uuid', 'member_id', 'channel', 'template', 'payload', 'status', 'attempts',
    'queued_at', 'scheduled_at', 'processing_at', 'sent_at', 'cancelled_at', 'error',
  ];

  protected static function booted(): void
  {
    static::creating(function (MemberNotificationQueue $item): void {
      if (empty($item->uuid)) {
        $item->uuid = (string) Str::uuid();
      }
    });
  }

  protected function casts(): array
  {
    return [
      'payload' => 'array',
      'queued_at' => 'datetime',
      'scheduled_at' => 'datetime',
      'processing_at' => 'datetime',
      'sent_at' => 'datetime',
      'cancelled_at' => 'datetime',
    ];
  }

  public function getRouteKeyName(): string
  {
    return 'uuid';
  }

  public function member(): BelongsTo
  {
    return $this->belongsTo(Member::class);
  }
}
