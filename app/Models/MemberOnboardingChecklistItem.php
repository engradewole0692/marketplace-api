<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class MemberOnboardingChecklistItem extends Model
{
  protected $fillable = [
    'uuid', 'member_id', 'step_key', 'label', 'is_completed', 'completed_at',
    'completed_by', 'notes', 'sort_order',
  ];

  protected static function booted(): void
  {
    static::creating(function (MemberOnboardingChecklistItem $item): void {
      if (empty($item->uuid)) {
        $item->uuid = (string) Str::uuid();
      }
    });
  }

  protected function casts(): array
  {
    return [
      'is_completed' => 'boolean',
      'completed_at' => 'datetime',
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

  public function completer(): BelongsTo
  {
    return $this->belongsTo(User::class, 'completed_by');
  }
}
