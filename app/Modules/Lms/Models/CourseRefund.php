<?php

declare(strict_types=1);

namespace App\Modules\Lms\Models;

use App\Models\User;
use App\Modules\Donations\Models\Donation;
use App\Modules\Lms\Enums\CourseRefundStatus;
use App\Modules\Lms\Support\HasLmsUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CourseRefund extends Model
{
  use HasLmsUuid;

  protected $table = 'lms_course_refunds';

  /** @var list<string> */
  protected $fillable = [
    'uuid', 'order_id', 'donation_id', 'amount', 'currency', 'status',
    'reason', 'notes', 'gateway_refunded', 'requested_by_user_id',
    'processed_by_user_id', 'processed_at', 'metadata',
  ];

  protected function casts(): array
  {
    return [
      'status' => CourseRefundStatus::class,
      'amount' => 'decimal:2',
      'gateway_refunded' => 'boolean',
      'processed_at' => 'datetime',
      'metadata' => 'array',
    ];
  }

  public function getRouteKeyName(): string
  {
    return 'uuid';
  }

  public function order(): BelongsTo
  {
    return $this->belongsTo(CourseOrder::class, 'order_id');
  }

  public function donation(): BelongsTo
  {
    return $this->belongsTo(Donation::class, 'donation_id');
  }

  public function requester(): BelongsTo
  {
    return $this->belongsTo(User::class, 'requested_by_user_id');
  }

  public function processor(): BelongsTo
  {
    return $this->belongsTo(User::class, 'processed_by_user_id');
  }
}
