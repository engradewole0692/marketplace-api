<?php

declare(strict_types=1);

namespace App\Modules\Lms\Models;

use App\Models\User;
use App\Modules\Lms\Enums\AssignmentType;
use App\Modules\Lms\Support\HasLmsUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Assignment extends Model
{
  use HasLmsUuid;
  use SoftDeletes;

  protected $table = 'lms_assignments';

  /** @var list<string> */
  protected $fillable = [
    'uuid', 'course_id', 'lesson_id', 'module_id', 'title', 'slug', 'type',
    'instructions', 'objective', 'rubric', 'max_score', 'pass_mark', 'max_attempts',
    'allow_resubmission', 'allow_attachments', 'max_attachments', 'due_at',
    'is_required', 'status', 'sort_order', 'metadata', 'created_by_user_id',
  ];

  protected function casts(): array
  {
    return [
      'type' => AssignmentType::class,
      'rubric' => 'array',
      'max_score' => 'integer',
      'pass_mark' => 'decimal:2',
      'max_attempts' => 'integer',
      'allow_resubmission' => 'boolean',
      'allow_attachments' => 'boolean',
      'max_attachments' => 'integer',
      'due_at' => 'datetime',
      'is_required' => 'boolean',
      'sort_order' => 'integer',
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

  public function lesson(): BelongsTo
  {
    return $this->belongsTo(Lesson::class, 'lesson_id');
  }

  public function module(): BelongsTo
  {
    return $this->belongsTo(CourseModule::class, 'module_id');
  }

  public function submissions(): HasMany
  {
    return $this->hasMany(AssignmentSubmission::class, 'assignment_id');
  }

  public function createdBy(): BelongsTo
  {
    return $this->belongsTo(User::class, 'created_by_user_id');
  }
}
