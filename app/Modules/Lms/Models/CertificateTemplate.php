<?php

declare(strict_types=1);

namespace App\Modules\Lms\Models;

use App\Models\User;
use App\Modules\Cms\Models\CmsMedia;
use App\Modules\Lms\Support\HasLmsUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class CertificateTemplate extends Model
{
  use HasLmsUuid;
  use SoftDeletes;

  protected $table = 'lms_certificate_templates';

  /** @var list<string> */
  protected $fillable = [
    'uuid', 'course_id', 'name', 'slug', 'html_body',
    'background_media_id', 'logo_media_id', 'watermark_media_id',
    'instructor_signature_media_id', 'director_signature_media_id',
    'is_active', 'is_default', 'sort_order',
    'created_by_user_id', 'updated_by_user_id',
  ];

  protected function casts(): array
  {
    return [
      'is_active' => 'boolean',
      'is_default' => 'boolean',
      'sort_order' => 'integer',
    ];
  }

  public function getRouteKeyName(): string
  {
    return 'uuid';
  }

  public function course(): BelongsTo
  {
    return $this->belongsTo(Course::class, 'course_id');
  }

  public function backgroundMedia(): BelongsTo
  {
    return $this->belongsTo(CmsMedia::class, 'background_media_id');
  }

  public function logoMedia(): BelongsTo
  {
    return $this->belongsTo(CmsMedia::class, 'logo_media_id');
  }

  public function watermarkMedia(): BelongsTo
  {
    return $this->belongsTo(CmsMedia::class, 'watermark_media_id');
  }

  public function instructorSignatureMedia(): BelongsTo
  {
    return $this->belongsTo(CmsMedia::class, 'instructor_signature_media_id');
  }

  public function directorSignatureMedia(): BelongsTo
  {
    return $this->belongsTo(CmsMedia::class, 'director_signature_media_id');
  }

  public function creator(): BelongsTo
  {
    return $this->belongsTo(User::class, 'created_by_user_id');
  }
}
