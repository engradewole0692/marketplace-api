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

  public const PURPOSE_DONATION = 'donation';

  public const PURPOSE_SCHOOL_ENROLLMENT = 'school_enrollment';

  /** metadata.purpose (set by each module's checkout) => payment_purpose classification */
  private const METADATA_PURPOSES = [
    'school_order' => self::PURPOSE_SCHOOL_ENROLLMENT,
    'course_order' => 'course_purchase',
    'event_registration' => 'event_registration',
    'counselling_payment' => 'counselling',
  ];

  protected $table = 'donations';

  protected $fillable = [
    'uuid', 'reference', 'payment_purpose', 'fund_id', 'country_id', 'member_id', 'form_submission_id',
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

  protected static function booted(): void
  {
    static::creating(function (Donation $donation): void {
      if (empty($donation->payment_purpose)) {
        $donation->payment_purpose = self::purposeFromMetadata(is_array($donation->metadata) ? $donation->metadata : []);
      }
    });
  }

  /**
   * @param  array<string, mixed>  $metadata
   */
  public static function purposeFromMetadata(array $metadata): string
  {
    return self::METADATA_PURPOSES[(string) ($metadata['purpose'] ?? '')] ?? self::PURPOSE_DONATION;
  }

  /**
   * Linked record for non-donation payments (e.g. the school order), taken from checkout metadata.
   *
   * @return array<string, string>|null
   */
  public function purposeReference(): ?array
  {
    $meta = is_array($this->metadata) ? $this->metadata : [];
    if (($this->payment_purpose ?? self::PURPOSE_DONATION) === self::PURPOSE_SCHOOL_ENROLLMENT) {
      return array_filter([
        'school_order_id' => $meta['school_order_uuid'] ?? null,
        'school_enrollment_id' => $meta['school_enrollment_uuid'] ?? null,
        'school_id' => $meta['school_uuid'] ?? null,
        'school_title' => $meta['school_title'] ?? null,
        'pricing_category' => $meta['pricing_category'] ?? null,
      ], fn ($v) => is_string($v) && $v !== '');
    }

    return null;
  }

  /** @return list<string> */
  public static function purposes(): array
  {
    return array_values(array_unique([self::PURPOSE_DONATION, ...array_values(self::METADATA_PURPOSES)]));
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
