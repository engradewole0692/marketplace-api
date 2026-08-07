<?php

declare(strict_types=1);

namespace App\Modules\Cms\Models;

use App\Models\User;
use App\Modules\Cms\Support\HasCmsUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CmsFormSubmissionNote extends Model
{
  use HasCmsUuid;

  protected $table = 'cms_form_submission_notes';

  protected $fillable = ['uuid', 'submission_id', 'author_id', 'body'];

  public function submission(): BelongsTo
  {
    return $this->belongsTo(CmsFormSubmission::class, 'submission_id');
  }

  public function author(): BelongsTo
  {
    return $this->belongsTo(User::class, 'author_id');
  }
}
