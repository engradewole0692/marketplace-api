<?php

declare(strict_types=1);

namespace App\Modules\Lms\Models;

use App\Modules\Lms\Enums\CatalogStatus;
use App\Modules\Lms\Support\HasLmsUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class CourseTag extends Model
{
  use HasLmsUuid;
  use SoftDeletes;

  protected $table = 'lms_course_tags';

  /** @var list<string> */
  protected $fillable = ['uuid', 'name', 'slug', 'status'];

  protected function casts(): array
  {
    return ['status' => CatalogStatus::class];
  }

  public function getRouteKeyName(): string
  {
    return 'uuid';
  }

  public function courses(): BelongsToMany
  {
    return $this->belongsToMany(Course::class, 'lms_course_tag', 'tag_id', 'course_id');
  }
}
