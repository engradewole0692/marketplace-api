<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuthenticationAuditLog extends Model
{
  public $timestamps = false;

  /**
   * @var list<string>
   */
  protected $fillable = [
    'user_id',
    'event_type',
    'email',
    'ip_address',
    'user_agent',
    'metadata',
    'created_at',
  ];

  /**
   * @return array<string, string>
   */
  protected function casts(): array
  {
    return [
      'metadata' => 'array',
      'created_at' => 'datetime',
    ];
  }

  public function user(): BelongsTo
  {
    return $this->belongsTo(User::class);
  }
}
