<?php

declare(strict_types=1);

namespace App\Modules\Donations\Models;

use App\Models\Member;
use App\Models\User;
use App\Modules\Cms\Models\CmsCountry;
use App\Modules\Cms\Models\CmsFormSubmission;
use App\Modules\Donations\Enums\DonationFrequency;
use App\Modules\Donations\Enums\DonationStatus;
use App\Modules\Donations\Enums\PaymentMethod;
use App\Modules\Donations\Support\HasDonationUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Donation extends Model
{
  use HasDonationUuid;
  use SoftDeletes;

  protected $table = 'donations';

  protected $fillable = [
    'uuid', 'reference', 'fund_id', 'country_id', 'member_id', 'form_submission_id',
    'amount', 'currency', 'status', 'frequency', 'is_anonymous', 'needs_tax_receipt',
    'donor_name', 'donor_email', 'donor_phone', 'payment_method', 'provider',
    'provider_intent_id', 'notes', 'metadata', 'paid_at', 'confirmed_by',
  ];

  protected function casts(): array
  {
    return [
      'amount' => 'decimal:2',
      'status' => DonationStatus::class,
      'frequency' => DonationFrequency::class,
      'payment_method' => PaymentMethod::class,
      'is_anonymous' => 'boolean',
      'needs_tax_receipt' => 'boolean',
      'metadata' => 'array',
      'paid_at' => 'datetime',
    ];
  }

  public function getRouteKeyName(): string
  {
    return 'uuid';
  }

  public function fund(): BelongsTo
  {
    return $this->belongsTo(DonationFund::class, 'fund_id');
  }

  public function country(): BelongsTo
  {
    return $this->belongsTo(CmsCountry::class, 'country_id');
  }

  public function member(): BelongsTo
  {
    return $this->belongsTo(Member::class, 'member_id');
  }

  public function formSubmission(): BelongsTo
  {
    return $this->belongsTo(CmsFormSubmission::class, 'form_submission_id');
  }

  public function confirmer(): BelongsTo
  {
    return $this->belongsTo(User::class, 'confirmed_by');
  }

  public function payments(): HasMany
  {
    return $this->hasMany(DonationPayment::class, 'donation_id');
  }

  public function receipt(): HasOne
  {
    return $this->hasOne(DonationReceipt::class, 'donation_id');
  }

  public function subscription(): HasOne
  {
    return $this->hasOne(DonationSubscription::class, 'donation_id');
  }

  public function displayDonorName(): string
  {
    return $this->is_anonymous ? 'Anonymous Donor' : ($this->donor_name ?: 'Guest Donor');
  }
}
