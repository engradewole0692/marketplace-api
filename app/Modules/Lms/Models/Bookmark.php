<?php

declare(strict_types=1);

namespace App\Modules\Lms\Models;

use App\Models\User;
use App\Modules\Lms\Support\HasLmsUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Bookmark extends Model
{
  use HasLmsUuid;

  protected $table = 'lms_bookmarks';

  /** @var list<string> */
  protected $fillable = ['uuid', 'user_id', 'lesson_id', 'note', 'position_seconds', 'label'];

  protected function casts(): array
  {
    return [
      'position_seconds' => 'integer',
    ];
  }

  public function getRouteKeyName(): string
  {
    return 'uuid';
  }

  public function user(): BelongsTo
  {
    return $this->belongsTo(User::class);
  }

  public function lesson(): BelongsTo
  {
    return $this->belongsTo(Lesson::class, 'lesson_id');
  }
}
