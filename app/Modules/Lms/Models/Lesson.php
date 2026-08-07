<?php

declare(strict_types=1);

namespace App\Modules\Lms\Models;

use App\Models\User;
use App\Modules\Cms\Models\CmsMedia;
use App\Modules\Lms\Enums\LessonType;
use App\Modules\Lms\Enums\ModuleStatus;
use App\Modules\Lms\Enums\VideoSource;
use App\Modules\Lms\Support\HasLmsUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Lesson extends Model
{
  use HasLmsUuid;
  use SoftDeletes;

  protected $table = 'lms_lessons';

  /** @var list<string> */
  protected $fillable = [
    'uuid', 'module_id', 'course_id', 'title', 'slug', 'summary', 'content',
    'sort_order', 'status', 'lesson_type', 'is_preview', 'duration_minutes',
    'video_source', 'youtube_video_id', 'youtube_url', 'video_media_id', 'embed_html',
    'is_mandatory', 'completion_threshold_percent',
    'created_by_user_id', 'updated_by_user_id',
  ];

  protected function casts(): array
  {
    return [
      'status' => ModuleStatus::class,
      'lesson_type' => LessonType::class,
      'video_source' => VideoSource::class,
      'is_preview' => 'boolean',
      'is_mandatory' => 'boolean',
      'sort_order' => 'integer',
      'completion_threshold_percent' => 'integer',
    ];
  }

  public function getRouteKeyName(): string
  {
    return 'uuid';
  }

  public function module(): BelongsTo
  {
    return $this->belongsTo(CourseModule::class, 'module_id');
  }

  public function course(): BelongsTo
  {
    return $this->belongsTo(Course::class, 'course_id');
  }

  public function videoMedia(): BelongsTo
  {
    return $this->belongsTo(CmsMedia::class, 'video_media_id');
  }

  public function resources(): HasMany
  {
    return $this->hasMany(LessonResource::class, 'lesson_id')->orderBy('sort_order');
  }

  public function createdBy(): BelongsTo
  {
    return $this->belongsTo(User::class, 'created_by_user_id');
  }
}
