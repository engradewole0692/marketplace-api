<?php

declare(strict_types=1);

namespace App\Modules\Lms\Models;

use App\Models\User;
use App\Modules\Lms\Support\HasLmsUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LmsCourseImport extends Model
{
  use HasLmsUuid;

  protected $table = 'lms_course_imports';

  /** @var list<string> */
  protected $fillable = [
    'uuid',
    'admin_user_id',
    'filename',
    'status',
    'publish_after_import',
    'create_missing_schools',
    'create_missing_categories',
    'create_missing_program_modules',
    'summary',
    'report',
    'settings',
  ];

  protected function casts(): array
  {
    return [
      'publish_after_import' => 'boolean',
      'create_missing_schools' => 'boolean',
      'create_missing_categories' => 'boolean',
      'create_missing_program_modules' => 'boolean',
      'summary' => 'array',
      'report' => 'array',
      'settings' => 'array',
    ];
  }

  public function getRouteKeyName(): string
  {
    return 'uuid';
  }

  public function administrator(): BelongsTo
  {
    return $this->belongsTo(User::class, 'admin_user_id');
  }
}
