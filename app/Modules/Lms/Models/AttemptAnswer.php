<?php

declare(strict_types=1);

namespace App\Modules\Lms\Models;

use App\Models\User;
use App\Modules\Lms\Support\HasLmsUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttemptAnswer extends Model
{
  use HasLmsUuid;

  protected $table = 'lms_attempt_answers';

  /** @var list<string> */
  protected $fillable = [
    'uuid', 'attempt_id', 'question_id', 'response_payload', 'is_correct',
    'points_awarded', 'points_possible', 'needs_manual_grading',
    'instructor_feedback', 'graded_by_user_id', 'graded_at',
  ];

  protected function casts(): array
  {
    return [
      'response_payload' => 'array',
      'is_correct' => 'boolean',
      'points_awarded' => 'decimal:2',
      'points_possible' => 'decimal:2',
      'needs_manual_grading' => 'boolean',
      'graded_at' => 'datetime',
    ];
  }

  public function getRouteKeyName(): string
  {
    return 'uuid';
  }

  public function attempt(): BelongsTo
  {
    return $this->belongsTo(AssessmentAttempt::class, 'attempt_id');
  }

  public function question(): BelongsTo
  {
    return $this->belongsTo(Question::class, 'question_id');
  }

  public function gradedBy(): BelongsTo
  {
    return $this->belongsTo(User::class, 'graded_by_user_id');
  }
}
