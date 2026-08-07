<?php

declare(strict_types=1);

namespace App\Modules\Cms\Models;

use App\Models\User;
use App\Modules\Cms\Support\HasCmsUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class CmsFormSubmissionAttachment extends Model
{
  use HasCmsUuid;

  protected $table = 'cms_form_submission_attachments';

  protected $fillable = [
    'uuid',
    'submission_id',
    'uploaded_by',
    'disk',
    'path',
    'file_name',
    'mime_type',
    'size',
  ];

  protected function casts(): array
  {
    return ['size' => 'integer'];
  }

  public function getRouteKeyName(): string
  {
    return 'uuid';
  }

  public function uploader(): BelongsTo
  {
    return $this->belongsTo(User::class, 'uploaded_by');
  }

  public function submission(): BelongsTo
  {
    return $this->belongsTo(CmsFormSubmission::class, 'submission_id');
  }

  public function url(): string
  {
    return Storage::disk($this->disk)->url($this->path);
  }
}
