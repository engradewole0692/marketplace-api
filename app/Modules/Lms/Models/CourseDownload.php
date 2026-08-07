<?php

declare(strict_types=1);

namespace App\Modules\Lms\Models;

use App\Modules\Cms\Models\CmsMedia;
use App\Modules\Lms\Support\HasLmsUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class CourseDownload extends Model
{
  use HasLmsUuid;
  use SoftDeletes;

  protected $table = 'lms_course_downloads';

  /** @var list<string> */
  protected $fillable = [
    'uuid', 'course_id', 'title', 'description', 'file_media_id',
    'external_url', 'sort_order', 'is_public',
  ];

  protected function casts(): array
  {
    return [
      'is_public' => 'boolean',
      'sort_order' => 'integer',
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

  public function fileMedia(): BelongsTo
  {
    return $this->belongsTo(CmsMedia::class, 'file_media_id');
  }
}
