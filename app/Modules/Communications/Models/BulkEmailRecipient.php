<?php

declare(strict_types=1);

namespace App\Modules\Communications\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BulkEmailRecipient extends Model
{
  protected $table = 'bulk_email_recipients';

  protected $fillable = [
    'bulk_email_job_id', 'email', 'name', 'user_id', 'status', 'error_message', 'sent_at',
  ];

  protected function casts(): array
  {
    return [
      'sent_at' => 'datetime',
    ];
  }

  public function job(): BelongsTo
  {
    return $this->belongsTo(BulkEmailJob::class, 'bulk_email_job_id');
  }
}
