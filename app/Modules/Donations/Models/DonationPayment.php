<?php

declare(strict_types=1);

namespace App\Modules\Donations\Models;

use App\Modules\Donations\Support\HasDonationUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DonationPayment extends Model
{
  use HasDonationUuid;

  protected $table = 'donation_payments';

  protected $fillable = [
    'uuid', 'donation_id', 'provider', 'provider_payment_id', 'amount', 'currency',
    'status', 'raw_payload', 'paid_at',
  ];

  protected function casts(): array
  {
    return [
      'amount' => 'decimal:2',
      'raw_payload' => 'array',
      'paid_at' => 'datetime',
    ];
  }

  public function donation(): BelongsTo
  {
    return $this->belongsTo(Donation::class, 'donation_id');
  }
}
