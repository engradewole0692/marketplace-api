<?php

declare(strict_types=1);

namespace App\Modules\Lms\Models;

use App\Models\User;
use App\Modules\Donations\Models\Donation;
use App\Modules\Lms\Enums\CourseOrderStatus;
use App\Modules\Lms\Support\HasLmsUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class CourseOrder extends Model
{
  use HasLmsUuid;
  use SoftDeletes;

  protected $table = 'lms_course_orders';

  /** @var list<string> */
  protected $fillable = [
    'uuid', 'order_number', 'enrollment_id', 'course_id', 'user_id',
    'list_amount', 'discount_amount', 'amount', 'currency', 'coupon_code',
    'learner_type', 'status', 'payment_method', 'donation_id',
    'provider_intent_id', 'paid_at', 'cancelled_at', 'pricing_snapshot', 'metadata',
  ];

  protected function casts(): array
  {
    return [
      'status' => CourseOrderStatus::class,
      'list_amount' => 'decimal:2',
      'discount_amount' => 'decimal:2',
      'amount' => 'decimal:2',
      'paid_at' => 'datetime',
      'cancelled_at' => 'datetime',
      'pricing_snapshot' => 'array',
      'metadata' => 'array',
    ];
  }

  public function getRouteKeyName(): string
  {
    return 'uuid';
  }

  public function enrollment(): BelongsTo
  {
    return $this->belongsTo(Enrollment::class, 'enrollment_id');
  }

  public function course(): BelongsTo
  {
    return $this->belongsTo(Course::class, 'course_id');
  }

  public function user(): BelongsTo
  {
    return $this->belongsTo(User::class);
  }

  public function donation(): BelongsTo
  {
    return $this->belongsTo(Donation::class, 'donation_id');
  }

  public function invoice(): HasOne
  {
    return $this->hasOne(CourseInvoice::class, 'order_id');
  }

  public function invoices(): HasMany
  {
    return $this->hasMany(CourseInvoice::class, 'order_id');
  }

  public function refunds(): HasMany
  {
    return $this->hasMany(CourseRefund::class, 'order_id');
  }
}
