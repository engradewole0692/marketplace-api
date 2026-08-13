<?php

declare(strict_types=1);

namespace App\Modules\Lms\Models;

use App\Models\User;
use App\Modules\Lms\Enums\ModuleStatus;
use App\Modules\Lms\Support\HasLmsUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class CourseModule extends Model
{
  use HasLmsUuid;
  use SoftDeletes;

  protected $table = 'lms_modules';

  /** @var list<string> */
  protected $fillable = [
    'uuid', 'course_id', 'title', 'slug', 'description', 'sort_order', 'status',
    'is_preview', 'duration_minutes', 'created_by_user_id', 'updated_by_user_id',
  ];

  protected function casts(): array
  {
    return [
      'status' => ModuleStatus::class,
      'is_preview' => 'boolean',
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

  public function lessons(): HasMany
  {
    return $this->hasMany(Lesson::class, 'module_id')->orderBy('sort_order');
  }

  public function assessments(): HasMany
  {
    return $this->hasMany(Assessment::class, 'module_id');
  }

  public function createdBy(): BelongsTo
  {
    return $this->belongsTo(User::class, 'created_by_user_id');
  }
}
