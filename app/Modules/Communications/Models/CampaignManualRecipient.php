<?php

declare(strict_types=1);

namespace App\Modules\Communications\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CampaignManualRecipient extends Model
{
  protected $table = 'communication_campaign_manual_recipients';

  /** @var list<string> */
  protected $fillable = ['bulk_email_job_id', 'email'];

  public function job(): BelongsTo
  {
    return $this->belongsTo(BulkEmailJob::class, 'bulk_email_job_id');
  }
}
