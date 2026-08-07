<?php

declare(strict_types=1);

namespace App\Modules\Lms\Models;

use App\Models\User;
use App\Modules\Lms\Support\HasLmsUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class LessonNote extends Model
{
  use HasLmsUuid;
  use SoftDeletes;

  protected $table = 'lms_lesson_notes';

  /** @var list<string> */
  protected $fillable = [
    'uuid', 'user_id', 'lesson_id', 'enrollment_id', 'title', 'body', 'position_seconds',
  ];

  protected function casts(): array
  {
    return [
      'position_seconds' => 'integer',
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

  public function lesson(): BelongsTo
  {
    return $this->belongsTo(Lesson::class, 'lesson_id');
  }

  public function enrollment(): BelongsTo
  {
    return $this->belongsTo(Enrollment::class, 'enrollment_id');
  }
}
