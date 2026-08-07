<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\MemberTimelineEventType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class MemberTimeline extends Model
{
  /**
   * @var list<string>
   */
  protected $fillable = [
    'uuid',
    'member_id',
    'event_type',
    'description',
    'actor_id',
    'metadata',
    'occurred_at',
  ];

  protected static function booted(): void
  {
    static::creating(function (MemberTimeline $timeline): void {
      if (empty($timeline->uuid)) {
        $timeline->uuid = (string) Str::uuid();
      }
    });
  }

  /**
   * @return array<string, string>
   */
  protected function casts(): array
  {
    return [
      'event_type' => MemberTimelineEventType::class,
      'metadata' => 'array',
      'occurred_at' => 'datetime',
    ];
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
