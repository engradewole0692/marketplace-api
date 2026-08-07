<?php

declare(strict_types=1);

namespace App\Modules\Lms\Models;

use App\Models\User;
use App\Modules\Lms\Enums\AssessmentStatus;
use App\Modules\Lms\Enums\AssessmentType;
use App\Modules\Lms\Support\HasLmsUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Assessment extends Model
{
  use HasLmsUuid;
  use SoftDeletes;

  protected $table = 'lms_assessments';

  /** @var list<string> */
  protected $fillable = [
    'uuid', 'course_id', 'module_id', 'lesson_id', 'title', 'slug', 'description',
    'assessment_type', 'status', 'pass_mark', 'time_limit_seconds', 'max_attempts',
    'retake_cooldown_minutes', 'randomize_questions', 'random_question_count',
    'negative_marking', 'negative_mark_value', 'show_immediate_result', 'allow_review',
    'requires_instructor_grading', 'settings',
    'created_by_user_id', 'updated_by_user_id',
  ];

  protected function casts(): array
  {
    return [
      'assessment_type' => AssessmentType::class,
      'status' => AssessmentStatus::class,
      'pass_mark' => 'decimal:2',
      'time_limit_seconds' => 'integer',
      'max_attempts' => 'integer',
      'retake_cooldown_minutes' => 'integer',
      'randomize_questions' => 'boolean',
      'random_question_count' => 'integer',
      'negative_marking' => 'boolean',
      'negative_mark_value' => 'decimal:2',
      'show_immediate_result' => 'boolean',
      'allow_review' => 'boolean',
      'requires_instructor_grading' => 'boolean',
      'settings' => 'array',
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

  public function module(): BelongsTo
  {
    return $this->belongsTo(CourseModule::class, 'module_id');
  }

  public function lesson(): BelongsTo
  {
    return $this->belongsTo(Lesson::class, 'lesson_id');
  }

  public function questions(): BelongsToMany
  {
    return $this->belongsToMany(Question::class, 'lms_assessment_questions', 'assessment_id', 'question_id')
      ->withPivot(['points', 'sort_order'])
      ->withTimestamps()
      ->orderByPivot('sort_order');
  }

  public function attempts(): HasMany
  {
    return $this->hasMany(AssessmentAttempt::class, 'assessment_id');
  }

  public function createdBy(): BelongsTo
  {
    return $this->belongsTo(User::class, 'created_by_user_id');
  }
}
