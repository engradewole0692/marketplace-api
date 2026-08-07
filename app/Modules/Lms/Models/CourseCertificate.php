<?php

declare(strict_types=1);

namespace App\Modules\Lms\Models;

use App\Models\User;
use App\Modules\Cms\Models\CmsMedia;
use App\Modules\Lms\Enums\CertificateStatus;
use App\Modules\Lms\Support\HasLmsUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class CourseCertificate extends Model
{
  use HasLmsUuid;
  use SoftDeletes;

  protected $table = 'lms_certificates';

  /** @var list<string> */
  protected $fillable = [
    'uuid', 'enrollment_id', 'course_id', 'user_id', 'certificate_number',
    'verification_code', 'status', 'issued_at', 'revoked_at',
    'certificate_media_id', 'template_id', 'issued_by_user_id',
    'download_count', 'metadata',
  ];

  protected function casts(): array
  {
    return [
      'status' => CertificateStatus::class,
      'issued_at' => 'datetime',
      'revoked_at' => 'datetime',
      'download_count' => 'integer',
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

  public function certificateMedia(): BelongsTo
  {
    return $this->belongsTo(CmsMedia::class, 'certificate_media_id');
  }

  public function template(): BelongsTo
  {
    return $this->belongsTo(CertificateTemplate::class, 'template_id');
  }

  public function issuer(): BelongsTo
  {
    return $this->belongsTo(User::class, 'issued_by_user_id');
  }

  public function getCertificateUrlAttribute(): ?string
  {
    return $this->certificateMedia?->url();
  }
}
