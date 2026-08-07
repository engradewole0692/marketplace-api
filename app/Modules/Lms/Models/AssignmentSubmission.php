<?php

declare(strict_types=1);

namespace App\Modules\Lms\Models;

use App\Models\User;
use App\Modules\Lms\Enums\AssignmentSubmissionStatus;
use App\Modules\Lms\Support\HasLmsUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class AssignmentSubmission extends Model
{
  use HasLmsUuid;
  use SoftDeletes;

  protected $table = 'lms_assignment_submissions';

  /** @var list<string> */
  protected $fillable = [
    'uuid', 'assignment_id', 'enrollment_id', 'user_id', 'attempt_number', 'status',
    'essay_body', 'objective_answers', 'attachments', 'score', 'max_score',
    'teacher_comments', 'submitted_at', 'returned_at', 'graded_at',
    'graded_by_user_id', 'metadata',
  ];

  protected function casts(): array
  {
    return [
      'status' => AssignmentSubmissionStatus::class,
      'objective_answers' => 'array',
      'attachments' => 'array',
      'score' => 'decimal:2',
      'max_score' => 'decimal:2',
      'submitted_at' => 'datetime',
      'returned_at' => 'datetime',
      'graded_at' => 'datetime',
      'metadata' => 'array',
    ];
  }

  public function getRouteKeyName(): string
  {
    return 'uuid';
  }

  public function assignment(): BelongsTo
  {
    return $this->belongsTo(Assignment::class, 'assignment_id');
  }

  public function enrollment(): BelongsTo
  {
    return $this->belongsTo(Enrollment::class, 'enrollment_id');
  }

  public function user(): BelongsTo
  {
    return $this->belongsTo(User::class);
  }

  public function gradedBy(): BelongsTo
  {
    return $this->belongsTo(User::class, 'graded_by_user_id');
  }
}
