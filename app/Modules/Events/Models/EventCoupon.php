<?php

declare(strict_types=1);

namespace App\Modules\Events\Models;

use App\Modules\Events\Enums\CouponDiscountType;
use App\Modules\Events\Support\HasEventUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventCoupon extends Model
{
  use HasEventUuid;

  /**
   * @var list<string>
   */
  protected $fillable = [
    'uuid',
    'event_id',
    'code',
    'discount_type',
    'discount_value',
    'max_uses',
    'used_count',
    'starts_at',
    'ends_at',
    'is_active',
  ];

  /**
   * @return array<string, string>
   */
  protected function casts(): array
  {
    return [
      'discount_type' => CouponDiscountType::class,
      'discount_value' => 'decimal:2',
      'max_uses' => 'integer',
      'used_count' => 'integer',
      'starts_at' => 'datetime',
      'ends_at' => 'datetime',
      'is_active' => 'boolean',
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

  public function isRedeemable(): bool
  {
    if (! $this->is_active) {
      return false;
    }

    $now = now();
    if ($this->starts_at !== null && $now->lt($this->starts_at)) {
      return false;
    }
    if ($this->ends_at !== null && $now->gt($this->ends_at)) {
      return false;
    }
    if ($this->max_uses !== null && $this->used_count >= $this->max_uses) {
      return false;
    }

    return true;
  }
}
