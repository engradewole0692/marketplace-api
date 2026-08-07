<?php

declare(strict_types=1);

namespace App\Modules\Lms\Models;

use App\Modules\Lms\Enums\CatalogStatus;
use App\Modules\Lms\Support\HasLmsUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class CourseLanguage extends Model
{
  use HasLmsUuid;
  use SoftDeletes;

  protected $table = 'lms_course_languages';

  /** @var list<string> */
  protected $fillable = ['uuid', 'name', 'code', 'status'];

  protected function casts(): array
  {
    return ['status' => CatalogStatus::class];
  }

  public function getRouteKeyName(): string
  {
    return 'uuid';
  }

  public function courses(): HasMany
  {
    return $this->hasMany(Course::class, 'language_id');
  }
}
