<?php

declare(strict_types=1);

namespace App\Modules\Lms\Models;

use App\Modules\Lms\Support\HasLmsUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class CourseFaq extends Model
{
  use HasLmsUuid;
  use SoftDeletes;

  protected $table = 'lms_course_faqs';

  /** @var list<string> */
  protected $fillable = ['uuid', 'course_id', 'question', 'answer', 'sort_order'];

  protected function casts(): array
  {
    return ['sort_order' => 'integer'];
  }

  public function getRouteKeyName(): string
  {
    return 'uuid';
  }

  public function course(): BelongsTo
  {
    return $this->belongsTo(Course::class, 'course_id');
  }
}
