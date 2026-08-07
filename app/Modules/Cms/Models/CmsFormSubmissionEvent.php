<?php

declare(strict_types=1);

namespace App\Modules\Cms\Models;

use App\Models\User;
use App\Modules\Cms\Support\HasCmsUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CmsFormSubmissionEvent extends Model
{
  use HasCmsUuid;

  protected $table = 'cms_form_submission_events';

  protected $fillable = [
    'uuid',
    'submission_id',
    'actor_id',
    'event_type',
    'title',
    'body',
    'meta',
  ];

  protected function casts(): array
  {
    return ['meta' => 'array'];
  }

  public function actor(): BelongsTo
  {
    return $this->belongsTo(User::class, 'actor_id');
  }

  public function submission(): BelongsTo
  {
    return $this->belongsTo(CmsFormSubmission::class, 'submission_id');
  }
}
