<?php

declare(strict_types=1);

namespace App\Modules\Lms\Models;

use App\Models\User;
use App\Modules\Lms\Enums\AttemptStatus;
use App\Modules\Lms\Support\HasLmsUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class AssessmentAttempt extends Model
{
  use HasLmsUuid;
  use SoftDeletes;

  protected $table = 'lms_assessment_attempts';

  /** @var list<string> */
  protected $fillable = [
    'uuid', 'assessment_id', 'enrollment_id', 'user_id', 'attempt_number', 'status',
    'started_at', 'submitted_at', 'expires_at', 'graded_at',
    'score', 'max_score', 'percentage', 'grade', 'passed', 'remarks',
    'question_order', 'graded_by_user_id', 'metadata',
  ];

  protected function casts(): array
  {
    return [
      'status' => AttemptStatus::class,
      'started_at' => 'datetime',
      'submitted_at' => 'datetime',
      'expires_at' => 'datetime',
      'graded_at' => 'datetime',
      'score' => 'decimal:2',
      'max_score' => 'decimal:2',
      'percentage' => 'decimal:2',
      'passed' => 'boolean',
      'question_order' => 'array',
      'metadata' => 'array',
      'attempt_number' => 'integer',
    ];
  }

  public function getRouteKeyName(): string
  {
    return 'uuid';
  }

  public function assessment(): BelongsTo
  {
    return $this->belongsTo(Assessment::class, 'assessment_id');
  }

  public function enrollment(): BelongsTo
  {
    return $this->belongsTo(Enrollment::class, 'enrollment_id');
  }

  public function user(): BelongsTo
  {
    return $this->belongsTo(User::class);
  }

  public function answers(): HasMany
  {
    return $this->hasMany(AttemptAnswer::class, 'attempt_id');
  }

  public function gradedBy(): BelongsTo
  {
    return $this->belongsTo(User::class, 'graded_by_user_id');
  }
}
