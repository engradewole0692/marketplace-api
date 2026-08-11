<?php

declare(strict_types=1);

namespace App\Modules\Communications\Models;

use App\Models\User;
use App\Modules\Communications\Enums\EmailLogStatus;
use App\Modules\Communications\Support\HasCommunicationUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommunicationEmailLog extends Model
{
  use HasCommunicationUuid;

  protected $table = 'communication_email_logs';

  /** @var list<string> */
  protected $fillable = [
    'uuid',
    'template_id',
    'event_key',
    'section',
    'recipient_email',
    'sender_email',
    'subject',
    'status',
    'is_test',
    'error_message',
    'related_type',
    'related_id',
    'user_id',
    'metadata',
    'sent_at',
    'failed_at',
  ];

  protected function casts(): array
  {
    return [
      'status' => EmailLogStatus::class,
      'is_test' => 'boolean',
      'metadata' => 'array',
      'sent_at' => 'datetime',
      'failed_at' => 'datetime',
    ];
  }

  public function getRouteKeyName(): string
  {
    return 'uuid';
  }

  public function template(): BelongsTo
  {
    return $this->belongsTo(CommunicationTemplate::class, 'template_id');
  }

  public function user(): BelongsTo
  {
    return $this->belongsTo(User::class);
  }
}
