<?php

declare(strict_types=1);

namespace App\Modules\Lms\Models;

use App\Models\User;
use App\Modules\Lms\Enums\CatalogStatus;
use App\Modules\Lms\Enums\CouponAppliesTo;
use App\Modules\Lms\Enums\CouponDiscountType;
use App\Modules\Lms\Support\HasLmsUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class CourseCoupon extends Model
{
  use HasLmsUuid;
  use SoftDeletes;

  protected $table = 'lms_coupons';

  /** @var list<string> */
  protected $fillable = [
    'uuid', 'code', 'name', 'discount_type', 'discount_value', 'applies_to',
    'course_id', 'max_redemptions', 'redeemed_count', 'starts_at', 'ends_at',
    'status', 'metadata', 'created_by_user_id', 'updated_by_user_id',
  ];

  protected function casts(): array
  {
    return [
      'discount_type' => CouponDiscountType::class,
      'applies_to' => CouponAppliesTo::class,
      'status' => CatalogStatus::class,
      'discount_value' => 'decimal:2',
      'starts_at' => 'datetime',
      'ends_at' => 'datetime',
      'metadata' => 'array',
    ];
  }

  public function getRouteKeyName(): string
  {
    return 'uuid';
  }

  public function course(): BelongsTo
  {
    return $this->belongsTo(Course::class, 'course_id');
  }

  public function createdBy(): BelongsTo
  {
    return $this->belongsTo(User::class, 'created_by_user_id');
  }

  public function isCurrentlyValid(): bool
  {
    if ($this->status !== CatalogStatus::Active) {
      return false;
    }
    $now = now();
    if ($this->starts_at && $now->lt($this->starts_at)) {
      return false;
    }
    if ($this->ends_at && $now->gt($this->ends_at)) {
      return false;
    }
    if ($this->max_redemptions !== null && $this->redeemed_count >= $this->max_redemptions) {
      return false;
    }

    return true;
  }
}
