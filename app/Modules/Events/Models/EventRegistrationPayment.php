<?php

declare(strict_types=1);

namespace App\Modules\Events\Models;

use App\Models\User;
use App\Modules\Events\Enums\PaymentMethodType;
use App\Modules\Events\Enums\PaymentStatus;
use App\Modules\Events\Support\HasEventUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventRegistrationPayment extends Model
{
  use HasEventUuid;

  /**
   * @var list<string>
   */
  protected $fillable = [
    'uuid',
    'registration_id',
    'event_id',
    'amount',
    'currency',
    'status',
    'payment_method',
    'coupon_id',
    'donation_id',
    'notes',
    'approved_by_user_id',
    'paid_at',
  ];

  /**
   * @return array<string, string>
   */
  protected function casts(): array
  {
    return [
      'amount' => 'decimal:2',
      'status' => PaymentStatus::class,
      'payment_method' => PaymentMethodType::class,
      'paid_at' => 'datetime',
    ];
  }

  public function getRouteKeyName(): string
  {
    return 'uuid';
  }

  public function registration(): BelongsTo
  {
    return $this->belongsTo(EventRegistration::class, 'registration_id');
  }

  public function event(): BelongsTo
  {
    return $this->belongsTo(Event::class);
  }

  public function coupon(): BelongsTo
  {
    return $this->belongsTo(EventCoupon::class, 'coupon_id');
  }

  public function approver(): BelongsTo
  {
    return $this->belongsTo(User::class, 'approved_by_user_id');
  }
}
