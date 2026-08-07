<?php

declare(strict_types=1);

namespace App\Modules\Lms\Models;

use App\Modules\Lms\Enums\ProgressStatus;
use App\Modules\Lms\Support\HasLmsUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LessonProgress extends Model
{
  use HasLmsUuid;

  protected $table = 'lms_lesson_progress';

  /** @var list<string> */
  protected $fillable = [
    'uuid', 'enrollment_id', 'lesson_id', 'status', 'progress_percent',
    'started_at', 'completed_at', 'last_position_seconds', 'time_spent_seconds',
  ];

  protected function casts(): array
  {
    return [
      'status' => ProgressStatus::class,
      'progress_percent' => 'decimal:2',
      'started_at' => 'datetime',
      'completed_at' => 'datetime',
      'last_position_seconds' => 'integer',
      'time_spent_seconds' => 'integer',
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

  public function lesson(): BelongsTo
  {
    return $this->belongsTo(Lesson::class, 'lesson_id');
  }
}
