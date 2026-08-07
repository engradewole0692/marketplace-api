<?php

declare(strict_types=1);

namespace App\Modules\Counselling\Models;

use App\Models\User;
use App\Modules\Counselling\Support\HasCounsellingUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class CounsellingFeedback extends Model
{
  use HasCounsellingUuid;
  use SoftDeletes;

  protected $table = 'counselling_feedback';

  /** @var list<string> */
  protected $fillable = [
    'uuid',
    'case_id',
    'user_id',
    'rating',
    'comment',
  ];

  protected function casts(): array
  {
    return [
      'rating' => 'integer',
    ];
  }

  public function getRouteKeyName(): string
  {
    return 'uuid';
  }

  public function case(): BelongsTo
  {
    return $this->belongsTo(CounsellingCase::class, 'case_id');
  }

  public function user(): BelongsTo
  {
    return $this->belongsTo(User::class);
  }
}
