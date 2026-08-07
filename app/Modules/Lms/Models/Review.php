<?php

declare(strict_types=1);

namespace App\Modules\Lms\Models;

use App\Models\User;
use App\Modules\Lms\Enums\ReviewStatus;
use App\Modules\Lms\Support\HasLmsUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Review extends Model
{
  use HasLmsUuid;
  use SoftDeletes;

  protected $table = 'lms_reviews';

  /** @var list<string> */
  protected $fillable = [
    'uuid', 'course_id', 'user_id', 'enrollment_id', 'rating', 'title', 'body', 'status',
  ];

  protected function casts(): array
  {
    return [
      'status' => ReviewStatus::class,
      'rating' => 'integer',
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

  public function enrollment(): BelongsTo
  {
    return $this->belongsTo(Enrollment::class, 'enrollment_id');
  }
}
