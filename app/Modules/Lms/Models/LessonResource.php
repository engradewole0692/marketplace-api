<?php

declare(strict_types=1);

namespace App\Modules\Lms\Models;

use App\Modules\Cms\Models\CmsMedia;
use App\Modules\Lms\Enums\ResourceType;
use App\Modules\Lms\Support\HasLmsUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class LessonResource extends Model
{
  use HasLmsUuid;
  use SoftDeletes;

  protected $table = 'lms_lesson_resources';

  /** @var list<string> */
  protected $fillable = [
    'uuid', 'lesson_id', 'title', 'resource_type', 'file_media_id',
    'external_url', 'sort_order', 'is_downloadable', 'access_level', 'is_preview_only',
  ];

  protected function casts(): array
  {
    return [
      'resource_type' => ResourceType::class,
      'access_level' => \App\Modules\Lms\Enums\ResourceAccessLevel::class,
      'is_downloadable' => 'boolean',
      'is_preview_only' => 'boolean',
      'sort_order' => 'integer',
    ];
  }

  public function getRouteKeyName(): string
  {
    return 'uuid';
  }

  public function lesson(): BelongsTo
  {
    return $this->belongsTo(Lesson::class, 'lesson_id');
  }

  public function fileMedia(): BelongsTo
  {
    return $this->belongsTo(CmsMedia::class, 'file_media_id');
  }
}
