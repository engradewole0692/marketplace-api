<?php

declare(strict_types=1);

namespace App\Modules\Communications\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class PlatformNotification extends Model
{
  use SoftDeletes;

  protected $table = 'platform_notifications';

  protected $fillable = [
    'uuid', 'user_id', 'role_slug', 'target_type', 'country_id', 'region_id', 'ministry_id',
    'type', 'title', 'body', 'action_url', 'icon',
    'related_type', 'related_id',
    'is_read', 'read_at',
    'sender_id',
  ];

  protected function casts(): array
  {
    return [
      'is_read' => 'boolean',
      'read_at' => 'datetime',
    ];
  }

  public function getRouteKeyName(): string
  {
    return 'uuid';
  }

  public function user(): BelongsTo
  {
    return $this->belongsTo(\App\Models\User::class, 'user_id');
  }

  public function sender(): BelongsTo
  {
    return $this->belongsTo(\App\Models\User::class, 'sender_id');
  }
}
