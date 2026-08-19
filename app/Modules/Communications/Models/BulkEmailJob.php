<?php

declare(strict_types=1);

namespace App\Modules\Communications\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class BulkEmailJob extends Model
{
  use SoftDeletes;

  protected $table = 'bulk_email_jobs';

  protected $fillable = [
    'uuid', 'subject', 'html_body', 'text_body', 'from_name', 'from_email',
    'recipient_filters', 'estimated_count', 'sent_count', 'failed_count',
    'status', 'created_by', 'queued_at', 'started_at', 'completed_at',
  ];

  protected function casts(): array
  {
    return [
      'recipient_filters' => 'array',
      'estimated_count' => 'integer',
      'sent_count' => 'integer',
      'failed_count' => 'integer',
      'queued_at' => 'datetime',
      'started_at' => 'datetime',
      'completed_at' => 'datetime',
    ];
  }

  public function getRouteKeyName(): string
  {
    return 'uuid';
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
