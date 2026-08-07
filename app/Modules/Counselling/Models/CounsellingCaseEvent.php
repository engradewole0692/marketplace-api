<?php

declare(strict_types=1);

namespace App\Modules\Counselling\Models;

use App\Models\User;
use App\Modules\Counselling\Support\HasCounsellingUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CounsellingCaseEvent extends Model
{
  use HasCounsellingUuid;

  protected $table = 'counselling_case_events';

  /** @var list<string> */
  protected $fillable = [
    'uuid',
    'case_id',
    'actor_user_id',
    'event_type',
    'title',
    'description',
    'metadata',
    'occurred_at',
  ];

  protected function casts(): array
  {
    return [
      'metadata' => 'array',
      'occurred_at' => 'datetime',
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

  public function actor(): BelongsTo
  {
    return $this->belongsTo(User::class, 'actor_user_id');
  }
}
