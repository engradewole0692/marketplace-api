<?php

declare(strict_types=1);

namespace App\Modules\Cms\Models;

use App\Models\Member;
use App\Models\User;
use App\Modules\Cms\Enums\TestimonialStatus;
use App\Modules\Cms\Support\HasCmsUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class CmsTestimonial extends Model
{
  use HasCmsUuid;
  use SoftDeletes;

  protected $table = 'cms_testimonials';

  protected $fillable = [
    'uuid',
    'author_name',
    'author_title',
    'author_location',
    'quote',
    'status',
    'category',
    'is_anonymous',
    'submitter_type',
    'submitter_email',
    'submitter_phone',
    'member_id',
    'photo_media_id',
    'video_media_id',
    'is_featured',
    'is_active',
    'show_on_homepage',
    'show_on_page',
    'rejection_reason',
    'moderated_by',
    'moderated_at',
    'source_submission_id',
    'sort_order',
    'created_by',
    'updated_by',
  ];

  protected function casts(): array
  {
    return [
      'status' => TestimonialStatus::class,
      'is_featured' => 'boolean',
      'is_active' => 'boolean',
      'is_anonymous' => 'boolean',
      'show_on_homepage' => 'boolean',
      'show_on_page' => 'boolean',
      'moderated_at' => 'datetime',
    ];
  }

  public function photoMedia(): BelongsTo
  {
    return $this->belongsTo(CmsMedia::class, 'photo_media_id');
  }

  public function videoMedia(): BelongsTo
  {
    return $this->belongsTo(CmsMedia::class, 'video_media_id');
  }

  public function member(): BelongsTo
  {
    return $this->belongsTo(Member::class, 'member_id');
  }

  public function moderator(): BelongsTo
  {
    return $this->belongsTo(User::class, 'moderated_by');
  }

  public function sourceSubmission(): BelongsTo
  {
    return $this->belongsTo(CmsFormSubmission::class, 'source_submission_id');
  }

  public function displayName(): string
  {
    if ($this->is_anonymous) {
      return 'Anonymous';
    }

    return $this->author_name ?: 'Guest';
  }

  public function getRouteKeyName(): string
  {
    return 'uuid';
  }
}
