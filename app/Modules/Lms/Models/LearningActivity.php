<?php

declare(strict_types=1);

namespace App\Modules\Lms\Models;

use App\Models\User;
use App\Modules\Lms\Support\HasLmsUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LearningActivity extends Model
{
  use HasLmsUuid;

  protected $table = 'lms_learning_activities';

  /** @var list<string> */
  protected $fillable = [
    'uuid', 'user_id', 'course_id', 'enrollment_id', 'lesson_id',
    'event_type', 'title', 'description', 'metadata', 'occurred_at',
  ];

  protected function casts(): array
  {
    return [
      'metadata' => 'array',
      'occurred_at' => 'datetime',
    ];
  }

  public function getRouteKeyName(): string
  {
    return 'uuid';
  }

  public function user(): BelongsTo
  {
    return $this->belongsTo(User::class);
  }

  public function course(): BelongsTo
  {
    return $this->belongsTo(Course::class, 'course_id');
  }

  public function enrollment(): BelongsTo
  {
    return $this->belongsTo(Enrollment::class, 'enrollment_id');
  }

  public function lesson(): BelongsTo
  {
    return $this->belongsTo(Lesson::class, 'lesson_id');
  }
}
