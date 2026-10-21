<?php

declare(strict_types=1);

namespace App\Modules\Communications\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A communication campaign. Historically email-only (hence the table name); now covers email, SMS and WhatsApp.
 *
 * Lifecycle: draft | scheduled | queued | processing | completed | partially_completed | failed | cancelled.
 * "sending" is a legacy alias of processing.
 */
class BulkEmailJob extends Model
{
  use SoftDeletes;

  public const STATUS_DRAFT = 'draft';

  public const STATUS_SCHEDULED = 'scheduled';

  public const STATUS_QUEUED = 'queued';

  public const STATUS_PROCESSING = 'processing';

  public const STATUS_COMPLETED = 'completed';

  public const STATUS_PARTIAL = 'partially_completed';

  public const STATUS_FAILED = 'failed';

  public const STATUS_CANCELLED = 'cancelled';

  protected $table = 'bulk_email_jobs';

  protected $fillable = [
    'uuid', 'subject', 'html_body', 'text_body', 'from_name', 'from_email',
    'recipient_filters', 'channels', 'module', 'message_type', 'whatsapp_template', 'preview_snapshot',
    'estimated_count', 'sent_count', 'accepted_count', 'delivered_count', 'failed_count', 'skipped_count',
    'status', 'created_by', 'queued_at', 'started_at', 'completed_at', 'scheduled_at',
    'cancelled_at', 'cancelled_by', 'last_error',
  ];

  protected function casts(): array
  {
    return [
      'recipient_filters' => 'array',
      'channels' => 'array',
      'whatsapp_template' => 'array',
      'preview_snapshot' => 'array',
      'estimated_count' => 'integer',
      'sent_count' => 'integer',
      'accepted_count' => 'integer',
      'delivered_count' => 'integer',
      'failed_count' => 'integer',
      'skipped_count' => 'integer',
      'queued_at' => 'datetime',
      'started_at' => 'datetime',
      'completed_at' => 'datetime',
      'scheduled_at' => 'datetime',
      'cancelled_at' => 'datetime',
    ];
  }

  public function getRouteKeyName(): string
  {
    return 'uuid';
  }

  /**
   * @return list<string>
   */
  public function channelList(): array
  {
    $channels = is_array($this->channels) && $this->channels !== []
      ? $this->channels
      : [strtolower((string) (($this->recipient_filters ?? [])['channel'] ?? 'email'))];

    return array_values(array_unique(array_filter(array_map('strval', $channels))));
  }

  public function creator(): BelongsTo
  {
    return $this->belongsTo(\App\Models\User::class, 'created_by');
  }

  public function recipients(): HasMany
  {
    return $this->hasMany(BulkEmailRecipient::class, 'bulk_email_job_id');
  }
}
