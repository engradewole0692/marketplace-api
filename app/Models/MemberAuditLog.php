<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MemberAuditLog extends Model
{
  /**
   * @var list<string>
   */
  protected $fillable = [
    'event_type',
    'member_id',
    'actor_id',
    'old_values',
    'new_values',
    'metadata',
    'ip_address',
    'user_agent',
  ];

  /**
   * @return array<string, string>
   */
  protected function casts(): array
  {
    return [
      'old_values' => 'array',
      'new_values' => 'array',
      'metadata' => 'array',
    ];
  }

  public function member(): BelongsTo
  {
    return $this->belongsTo(Member::class);
  }

  public function actor(): BelongsTo
  {
    return $this->belongsTo(User::class, 'actor_id');
  }
}
