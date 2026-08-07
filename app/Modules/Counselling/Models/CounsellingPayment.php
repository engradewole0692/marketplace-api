<?php

declare(strict_types=1);

namespace App\Modules\Counselling\Models;

use App\Modules\Counselling\Enums\ClientType;
use App\Modules\Counselling\Enums\PaymentStatus;
use App\Modules\Counselling\Support\HasCounsellingUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class CounsellingPayment extends Model
{
  use HasCounsellingUuid;
  use SoftDeletes;

  protected $table = 'counselling_payments';

  /** @var list<string> */
  protected $fillable = [
    'uuid',
    'case_id',
    'service_id',
    'status',
    'amount',
    'currency',
    'client_type',
    'payment_reference',
    'provider',
    'paid_at',
    'refunded_at',
    'metadata',
  ];

  protected function casts(): array
  {
    return [
      'status' => PaymentStatus::class,
      'amount' => 'decimal:2',
      'client_type' => ClientType::class,
      'paid_at' => 'datetime',
      'refunded_at' => 'datetime',
      'metadata' => 'array',
    ];
  }

  public function getRouteKeyName(): string
  {
    return 'uuid';
  }

  public function case(): BelongsTo
  {
    return $this->belongsTo(CounsellingCase::class, 'case_id');
  }

  public function service(): BelongsTo
  {
    return $this->belongsTo(CounsellingService::class, 'service_id');
  }
}
