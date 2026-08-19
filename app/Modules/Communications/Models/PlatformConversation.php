<?php

declare(strict_types=1);

namespace App\Modules\Communications\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class PlatformConversation extends Model
{
  use SoftDeletes;

  protected $table = 'platform_conversations';

  protected $fillable = [
    'uuid', 'type', 'subject',
    'module', 'module_entity_type', 'module_entity_id',
    'is_closed', 'last_message_at',
  ];

  protected function casts(): array
  {
    return [
      'is_closed' => 'boolean',
      'last_message_at' => 'datetime',
    ];
  }

  public function getRouteKeyName(): string
  {
    return 'uuid';
  }

  public function participants(): BelongsToMany
  {
    return $this->belongsToMany(\App\Models\User::class, 'platform_conversation_participants', 'conversation_id', 'user_id')
      ->withPivot(['role', 'last_read_at', 'is_muted'])
      ->withTimestamps();
  }

  public function messages(): HasMany
  {
    return $this->hasMany(PlatformMessage::class, 'conversation_id');
  }

  public function latestMessage(): HasMany
  {
    return $this->hasMany(PlatformMessage::class, 'conversation_id')->latest()->limit(1);
  }
}
