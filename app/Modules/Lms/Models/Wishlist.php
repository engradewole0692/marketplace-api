<?php

declare(strict_types=1);

namespace App\Modules\Lms\Models;

use App\Models\User;
use App\Modules\Lms\Support\HasLmsUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Wishlist extends Model
{
  use HasLmsUuid;

  protected $table = 'lms_wishlists';

  /** @var list<string> */
  protected $fillable = ['uuid', 'user_id', 'course_id'];

  public function getRouteKeyName(): string
  {
    return 'uuid';
  }

  public function user(): BelongsTo
  {
    return $this->belongsTo(User::class);
  }

  public function course(): BelongsTo
  {
    return $this->belongsTo(Course::class, 'course_id');
  }
}
