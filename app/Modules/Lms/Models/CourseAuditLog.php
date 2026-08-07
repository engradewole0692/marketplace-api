<?php

declare(strict_types=1);

namespace App\Modules\Lms\Models;

use App\Models\User;
use App\Modules\Lms\Support\HasLmsUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CourseAuditLog extends Model
{
  use HasLmsUuid;

  protected $table = 'lms_course_audit_logs';

  /** @var list<string> */
  protected $fillable = [
    'uuid', 'course_id', 'actor_id', 'event_type', 'description',
    'old_values', 'new_values', 'metadata', 'ip_address',
  ];

  protected function casts(): array
  {
    return [
      'old_values' => 'array',
      'new_values' => 'array',
      'metadata' => 'array',
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

  public function actor(): BelongsTo
  {
    return $this->belongsTo(User::class, 'actor_id');
  }
}
