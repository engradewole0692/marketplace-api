<?php

declare(strict_types=1);

namespace App\Modules\Lms\Models;

use App\Models\Member;
use App\Models\User;
use App\Modules\Lms\Enums\EnrollmentStatus;
use App\Modules\Lms\Enums\LearnerType;
use App\Modules\Lms\Support\HasLmsUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Enrollment extends Model
{
  use HasLmsUuid;
  use SoftDeletes;

  protected $table = 'lms_enrollments';

  /** @var list<string> */
  protected $fillable = [
    'uuid', 'course_id', 'user_id', 'member_id', 'learner_type', 'status',
    'enrolled_at', 'completed_at', 'expired_at', 'cancelled_at', 'locked_at',
    'restarted_at', 'last_accessed_at', 'progress_percent', 'price_paid', 'currency',
    'coupon_code', 'payment_reference', 'metadata',
  ];

  protected function casts(): array
  {
    return [
      'learner_type' => LearnerType::class,
      'status' => EnrollmentStatus::class,
      'enrolled_at' => 'datetime',
      'completed_at' => 'datetime',
      'expired_at' => 'datetime',
      'cancelled_at' => 'datetime',
      'locked_at' => 'datetime',
      'restarted_at' => 'datetime',
      'last_accessed_at' => 'datetime',
      'progress_percent' => 'decimal:2',
      'price_paid' => 'decimal:2',
      'metadata' => 'array',
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

  public function user(): BelongsTo
  {
    return $this->belongsTo(User::class);
  }

  public function member(): BelongsTo
  {
    return $this->belongsTo(Member::class);
  }

  public function lessonProgress(): HasMany
  {
    return $this->hasMany(LessonProgress::class, 'enrollment_id');
  }

  public function certificate(): HasOne
  {
    return $this->hasOne(CourseCertificate::class, 'enrollment_id');
  }
}
