<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MemberStatusTransition extends Model
{
  /**
   * @var list<string>
   */
  protected $fillable = [
    'member_id',
    'from_status',
    'to_status',
    'actor_id',
    'reason',
  ];

  public function member(): BelongsTo
  {
    return $this->belongsTo(Member::class);
  }

  public function actor(): BelongsTo
  {
    return $this->belongsTo(User::class, 'actor_id');
  }
}
