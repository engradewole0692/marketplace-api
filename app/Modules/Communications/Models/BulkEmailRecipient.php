<?php

declare(strict_types=1);

namespace App\Modules\Communications\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One message to one destination on one channel within a campaign.
 * `email` is kept for legacy rows; `destination` holds the normalized address (email or E.164 phone).
 */
class BulkEmailRecipient extends Model
{
  protected $table = 'bulk_email_recipients';

  protected $fillable = [
    'bulk_email_job_id', 'channel', 'email', 'destination', 'name', 'user_id', 'person_id', 'source',
    'status', 'error_message', 'error_code', 'skip_reason', 'sent_at',
    'idempotency_key', 'provider', 'provider_message_id', 'outbound_message_id', 'attempts',
    'segments', 'cost', 'currency', 'accepted_at', 'delivered_at', 'read_at', 'failed_at', 'last_event_at',
    'metadata',
  ];

  protected function casts(): array
  {
    return [
      'sent_at' => 'datetime',
      'accepted_at' => 'datetime',
      'delivered_at' => 'datetime',
      'read_at' => 'datetime',
      'failed_at' => 'datetime',
      'last_event_at' => 'datetime',
      'attempts' => 'integer',
      'segments' => 'integer',
      'cost' => 'float',
      'metadata' => 'array',
    ];
  }

  public function job(): BelongsTo
  {
    return $this->belongsTo(BulkEmailJob::class, 'bulk_email_job_id');
  }
}
