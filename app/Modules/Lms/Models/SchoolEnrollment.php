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
use Illuminate\Database\Eloquent\SoftDeletes;

class SchoolEnrollment extends Model
{
  use HasLmsUuid;
  use SoftDeletes;

  protected $table = 'lms_school_enrollments';

  /** @var list<string> */
  protected $fillable = [
    'uuid', 'school_id', 'user_id', 'member_id', 'learner_type', 'status',
    'price_paid', 'currency', 'payment_reference', 'enrolled_at', 'completed_at',
    'expired_at', 'cancelled_at', 'progress_percent', 'metadata',
  ];

  protected function casts(): array
  {
    return [
      'learner_type' => LearnerType::class,
      'status' => EnrollmentStatus::class,
      'price_paid' => 'decimal:2',
      'progress_percent' => 'decimal:2',
      'enrolled_at' => 'datetime',
      'completed_at' => 'datetime',
      'expired_at' => 'datetime',
      'cancelled_at' => 'datetime',
      'metadata' => 'array',
    ];
  }

  public function getRouteKeyName(): string
  {
    return 'uuid';
  }

  public function school(): BelongsTo
  {
    return $this->belongsTo(LmsSchool::class, 'school_id');
  }

  public function user(): BelongsTo
  {
    return $this->belongsTo(User::class);
  }

  public function member(): BelongsTo
  {
    return $this->belongsTo(Member::class);
  }
}
