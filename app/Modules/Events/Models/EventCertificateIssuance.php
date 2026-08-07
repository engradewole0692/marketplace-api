<?php

declare(strict_types=1);

namespace App\Modules\Events\Models;

use App\Models\Member;
use App\Models\User;
use App\Modules\Cms\Models\CmsMedia;
use App\Modules\Events\Enums\CertificateStatus;
use App\Modules\Events\Support\HasEventUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventCertificateIssuance extends Model
{
  use HasEventUuid;

  /**
   * @var list<string>
   */
  protected $fillable = [
    'uuid',
    'event_id',
    'registration_id',
    'member_id',
    'certificate_number',
    'verification_code',
    'status',
    'issued_at',
    'issued_by_user_id',
    'certificate_media_id',
    'template_id',
    'download_count',
    'reissued_from_id',
    'revoked_at',
    'revoked_by_user_id',
    'metadata',
  ];

  /**
   * @var list<string>
   */
  protected $appends = [
    'certificate_url',
  ];

  /**
   * @return array<string, string>
   */
  protected function casts(): array
  {
    return [
      'status' => CertificateStatus::class,
      'issued_at' => 'datetime',
      'revoked_at' => 'datetime',
      'metadata' => 'array',
      'download_count' => 'integer',
    ];
  }

  public function template(): BelongsTo
  {
    return $this->belongsTo(EventCertificateTemplate::class, 'template_id');
  }

  public function reissuedFrom(): BelongsTo
  {
    return $this->belongsTo(self::class, 'reissued_from_id');
  }

  public function event(): BelongsTo
  {
    return $this->belongsTo(Event::class);
  }

  public function registration(): BelongsTo
  {
    return $this->belongsTo(EventRegistration::class, 'registration_id');
  }

  public function member(): BelongsTo
  {
    return $this->belongsTo(Member::class);
  }

  public function certificate(): BelongsTo
  {
    return $this->belongsTo(CmsMedia::class, 'certificate_media_id');
  }

  public function issuer(): BelongsTo
  {
    return $this->belongsTo(User::class, 'issued_by_user_id');
  }

  public function revoker(): BelongsTo
  {
    return $this->belongsTo(User::class, 'revoked_by_user_id');
  }

  public function getCertificateUrlAttribute(): ?string
  {
    return $this->certificate?->url();
  }
}
