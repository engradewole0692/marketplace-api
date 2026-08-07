<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class IamAuditLog extends Model
{
  public $timestamps = false;

  /**
   * @var list<string>
   */
  protected $fillable = [
    'uuid',
    'event_type',
    'actor_id',
    'subject_type',
    'subject_id',
    'old_values',
    'new_values',
    'metadata',
    'ip_address',
    'user_agent',
    'created_at',
  ];

  protected static function booted(): void
  {
    static::creating(function (IamAuditLog $log): void {
      if (empty($log->uuid)) {
        $log->uuid = (string) Str::uuid();
      }

      if ($log->created_at === null) {
        $log->created_at = now();
      }
    });
  }

  /**
   * @return array<string, string>
   */
  protected function casts(): array
  {
    return [
      'old_values' => 'array',
      'new_values' => 'array',
      'metadata' => 'array',
      'created_at' => 'datetime',
    ];
  }

  public function actor(): BelongsTo
  {
    return $this->belongsTo(User::class, 'actor_id');
  }
}
