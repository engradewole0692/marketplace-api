<?php

declare(strict_types=1);

namespace App\Modules\Cms\Models;

use App\Models\User;
use App\Modules\Cms\Enums\FormSubmissionStatus;
use App\Modules\Cms\Enums\FormSubmissionType;
use App\Modules\Cms\Support\HasCmsUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class CmsFormSubmission extends Model
{
  use HasCmsUuid;
  use SoftDeletes;

  protected $table = 'cms_form_submissions';

  protected $fillable = [
    'uuid', 'type', 'status', 'payload', 'submitter_name', 'submitter_email',
    'source_ip', 'user_agent', 'processed_at', 'processed_by', 'assigned_to',
  ];

  protected function casts(): array
  {
    return [
      'type' => FormSubmissionType::class,
      'status' => FormSubmissionStatus::class,
      'payload' => 'array',
      'processed_at' => 'datetime',
    ];
  }

  public function notes(): HasMany
  {
    return $this->hasMany(CmsFormSubmissionNote::class, 'submission_id');
  }

  public function assignee(): BelongsTo
  {
    return $this->belongsTo(User::class, 'assigned_to');
  }

  public function attachments(): HasMany
  {
    return $this->hasMany(CmsFormSubmissionAttachment::class, 'submission_id');
  }

  public function events(): HasMany
  {
    return $this->hasMany(CmsFormSubmissionEvent::class, 'submission_id');
  }

  public function getRouteKeyName(): string
  {
    return 'uuid';
  }

  public function resolveRouteBinding($value, $field = null)
  {
    return $this->newQuery()
      ->withTrashed()
      ->where($field ?? $this->getRouteKeyName(), $value)
      ->firstOrFail();
  }
}
