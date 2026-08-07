<?php

declare(strict_types=1);

namespace App\Modules\Lms\Models;

use App\Models\User;
use App\Modules\Lms\Enums\AnnouncementStatus;
use App\Modules\Lms\Support\HasLmsUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Announcement extends Model
{
  use HasLmsUuid;
  use SoftDeletes;

  protected $table = 'lms_announcements';

  /** @var list<string> */
  protected $fillable = [
    'uuid', 'course_id', 'title', 'body', 'status', 'published_at',
    'created_by_user_id', 'updated_by_user_id',
  ];

  protected function casts(): array
  {
    return [
      'status' => AnnouncementStatus::class,
      'published_at' => 'datetime',
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

  public function createdBy(): BelongsTo
  {
    return $this->belongsTo(User::class, 'created_by_user_id');
  }
}
