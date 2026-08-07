<?php

declare(strict_types=1);

namespace App\Modules\Donations\Models;

use App\Models\Member;
use App\Modules\Donations\Support\HasDonationUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class DonationSubscription extends Model
{
  use HasDonationUuid;
  use SoftDeletes;

  protected $table = 'donation_subscriptions';

  protected $fillable = [
    'uuid', 'donation_id', 'member_id', 'fund_id', 'provider', 'provider_subscription_id',
    'interval', 'amount', 'currency', 'status', 'next_charge_at', 'cancelled_at',
  ];

  protected function casts(): array
  {
    return [
      'amount' => 'decimal:2',
      'next_charge_at' => 'datetime',
      'cancelled_at' => 'datetime',
    ];
  }

  public function getRouteKeyName(): string
  {
    return 'uuid';
  }

  public function donation(): BelongsTo
  {
    return $this->belongsTo(Donation::class, 'donation_id');
  }

  public function member(): BelongsTo
  {
    return $this->belongsTo(Member::class, 'member_id');
  }

  public function fund(): BelongsTo
  {
    return $this->belongsTo(DonationFund::class, 'fund_id');
  }
}
