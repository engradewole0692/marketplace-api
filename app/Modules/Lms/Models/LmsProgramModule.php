<?php

declare(strict_types=1);

namespace App\Modules\Lms\Models;

use App\Modules\Lms\Enums\ModuleStatus;
use App\Modules\Lms\Enums\ProgramModuleContainerType;
use App\Modules\Lms\Support\HasLmsUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class LmsProgramModule extends Model
{
  use HasLmsUuid;
  use SoftDeletes;

  protected $table = 'lms_program_modules';

  /** @var list<string> */
  protected $fillable = [
    'uuid', 'container_type', 'school_id', 'category_id', 'title', 'slug',
    'description', 'sort_order', 'status', 'metadata',
  ];

  protected function casts(): array
  {
    return [
      'container_type' => ProgramModuleContainerType::class,
      'status' => ModuleStatus::class,
      'sort_order' => 'integer',
      'metadata' => 'array',
    ];
  }

  public function getRouteKeyName(): string
  {
    return 'uuid';
  }

  public function school(): BelongsTo
  {
    return $this->belongsTo(LmsSchool::class, 'school_id');
  }

  public function category(): BelongsTo
  {
    return $this->belongsTo(CourseCategory::class, 'category_id');
  }

  public function courses(): HasMany
  {
    return $this->hasMany(Course::class, 'program_module_id')->orderBy('sort_order');
  }
}
