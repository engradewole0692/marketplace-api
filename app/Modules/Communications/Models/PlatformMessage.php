<?php

declare(strict_types=1);

namespace App\Modules\Communications\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlatformMessage extends Model
{
  protected $table = 'platform_messages';

  protected $fillable = [
    'uuid', 'conversation_id', 'sender_id', 'body', 'type', 'is_deleted', 'attachments',
  ];

  protected function casts(): array
  {
    return [
      'is_deleted' => 'boolean',
      'attachments' => 'array',
    ];
  }

  public function getRouteKeyName(): string
  {
    return 'uuid';
  }

  public function conversation(): BelongsTo
  {
    return $this->belongsTo(PlatformConversation::class, 'conversation_id');
  }

  public function sender(): BelongsTo
  {
    return $this->belongsTo(User::class, 'sender_id');
  }
}
