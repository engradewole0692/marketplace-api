<?php

declare(strict_types=1);

namespace App\Modules\Lms\Models;

use App\Modules\Lms\Enums\CatalogStatus;
use App\Modules\Lms\Support\HasLmsUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class CourseLevel extends Model
{
  use HasLmsUuid;
  use SoftDeletes;

  protected $table = 'lms_course_levels';

  /** @var list<string> */
  protected $fillable = ['uuid', 'name', 'slug', 'description', 'sort_order', 'status'];

  protected function casts(): array
  {
    return [
      'status' => CatalogStatus::class,
      'sort_order' => 'integer',
    ];
  }

  public function getRouteKeyName(): string
  {
    return 'uuid';
  }

  public function courses(): HasMany
  {
    return $this->hasMany(Course::class, 'level_id');
  }
}
