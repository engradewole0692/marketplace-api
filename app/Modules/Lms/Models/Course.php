<?php

declare(strict_types=1);

namespace App\Modules\Lms\Models;

use App\Models\User;
use App\Modules\Cms\Models\CmsMedia;
use App\Modules\Lms\Enums\CourseStatus;
use App\Modules\Lms\Support\HasLmsUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Course extends Model
{
  use HasLmsUuid;
  use SoftDeletes;

  protected $table = 'lms_courses';

  /** @var list<string> */
  protected $fillable = [
    'uuid', 'course_code', 'category_id', 'subcategory_id', 'level_id', 'language_id', 'difficulty',
    'title', 'slug', 'subtitle',
    'summary', 'description', 'requirements', 'learning_objectives',
    'status', 'access_scope', 'audience', 'primary_ministry_id', 'school_id', 'program_module_id',
    'is_featured', 'is_popular', 'is_recommended', 'sort_order',
    'certificate_enabled', 'certificate_template_id', 'certificate_requires_assessment_pass',
    'certificate_min_score', 'certificate_min_completion_percent', 'certificate_auto_issue',
    'assessment_required', 'assignment_required', 'passing_score', 'max_attempts', 'completion_rule',
    'cover_media_id', 'thumbnail_media_id', 'banner_media_id', 'trailer_media_id',
    'trailer_youtube_url', 'youtube_playlist_url',
    'member_price', 'public_price', 'is_free', 'visitor_free', 'member_free', 'promotional_price',
    'promotional_starts_at', 'promotional_ends_at', 'currency',
    'enrollment_count', 'average_rating', 'review_count', 'duration_minutes', 'estimated_completion_minutes',
    'published_at', 'scheduled_publish_at', 'seo_title', 'seo_description', 'seo_keywords', 'metadata',
    'created_by_user_id', 'updated_by_user_id', 'deleted_by_user_id',
  ];

  protected function casts(): array
  {
    return [
      'status' => CourseStatus::class,
      'access_scope' => \App\Modules\Lms\Enums\CourseAccessScope::class,
      'audience' => \App\Modules\Lms\Enums\CourseAudience::class,
      'completion_rule' => \App\Modules\Lms\Enums\CompletionRule::class,
      'is_featured' => 'boolean',
      'is_popular' => 'boolean',
      'is_recommended' => 'boolean',
      'certificate_enabled' => 'boolean',
      'certificate_requires_assessment_pass' => 'boolean',
      'certificate_auto_issue' => 'boolean',
      'assessment_required' => 'boolean',
      'assignment_required' => 'boolean',
      'is_free' => 'boolean',
      'visitor_free' => 'boolean',
      'member_free' => 'boolean',
      'member_price' => 'decimal:2',
      'public_price' => 'decimal:2',
      'promotional_price' => 'decimal:2',
      'passing_score' => 'decimal:2',
      'average_rating' => 'decimal:2',
      'requirements' => 'array',
      'learning_objectives' => 'array',
      'seo_keywords' => 'array',
      'promotional_starts_at' => 'datetime',
      'promotional_ends_at' => 'datetime',
      'published_at' => 'datetime',
      'scheduled_publish_at' => 'datetime',
      'metadata' => 'array',
    ];
  }

  public function getRouteKeyName(): string
  {
    return 'uuid';
  }

  public function scopePublished(Builder $query): Builder
  {
    return $query->where('status', CourseStatus::Published->value);
  }

  public function scopeForPublicListing(Builder $query): Builder
  {
    return $query->whereIn('status', [
      CourseStatus::Published->value,
      CourseStatus::ComingSoon->value,
    ]);
  }

  public function scopeFeatured(Builder $query): Builder
  {
    return $query->where('is_featured', true);
  }

  public function category(): BelongsTo
  {
    return $this->belongsTo(CourseCategory::class, 'category_id');
  }

  public function subcategory(): BelongsTo
  {
    return $this->belongsTo(CourseCategory::class, 'subcategory_id');
  }

  public function level(): BelongsTo
  {
    return $this->belongsTo(CourseLevel::class, 'level_id');
  }

  public function language(): BelongsTo
  {
    return $this->belongsTo(CourseLanguage::class, 'language_id');
  }

  public function coverMedia(): BelongsTo
  {
    return $this->belongsTo(CmsMedia::class, 'cover_media_id');
  }

  public function thumbnailMedia(): BelongsTo
  {
    return $this->belongsTo(CmsMedia::class, 'thumbnail_media_id');
  }

  public function bannerMedia(): BelongsTo
  {
    return $this->belongsTo(CmsMedia::class, 'banner_media_id');
  }

  public function trailerMedia(): BelongsTo
  {
    return $this->belongsTo(CmsMedia::class, 'trailer_media_id');
  }

  public function primaryMinistry(): BelongsTo
  {
    return $this->belongsTo(\App\Modules\Cms\Models\CmsMinistry::class, 'primary_ministry_id');
  }

  public function school(): BelongsTo
  {
    return $this->belongsTo(LmsSchool::class, 'school_id');
  }

  public function programModule(): BelongsTo
  {
    return $this->belongsTo(LmsProgramModule::class, 'program_module_id');
  }

  public function ministries(): BelongsToMany
  {
    return $this->belongsToMany(
      \App\Modules\Cms\Models\CmsMinistry::class,
      'lms_course_ministry',
      'course_id',
      'ministry_id',
    );
  }

  public function tags(): BelongsToMany
  {
    return $this->belongsToMany(CourseTag::class, 'lms_course_tag', 'course_id', 'tag_id');
  }

  public function instructors(): BelongsToMany
  {
    return $this->belongsToMany(Instructor::class, 'lms_course_instructor', 'course_id', 'instructor_id')
      ->withPivot(['is_primary', 'sort_order', 'role_label'])
      ->orderByPivot('sort_order');
  }

  public function modules(): HasMany
  {
    return $this->hasMany(CourseModule::class, 'course_id')->orderBy('sort_order');
  }

  public function lessons(): HasMany
  {
    return $this->hasMany(Lesson::class, 'course_id')->orderBy('sort_order');
  }

  public function enrollments(): HasMany
  {
    return $this->hasMany(Enrollment::class, 'course_id');
  }

  public function assignments(): HasMany
  {
    return $this->hasMany(Assignment::class, 'course_id')->orderBy('sort_order');
  }

  public function downloads(): HasMany
  {
    return $this->hasMany(CourseDownload::class, 'course_id')->orderBy('sort_order');
  }

  public function faqs(): HasMany
  {
    return $this->hasMany(CourseFaq::class, 'course_id')->orderBy('sort_order');
  }

  public function reviews(): HasMany
  {
    return $this->hasMany(Review::class, 'course_id');
  }

  public function certificates(): HasMany
  {
    return $this->hasMany(CourseCertificate::class, 'course_id');
  }

  public function certificateTemplate(): BelongsTo
  {
    return $this->belongsTo(CertificateTemplate::class, 'certificate_template_id');
  }

  public function announcements(): HasMany
  {
    return $this->hasMany(Announcement::class, 'course_id');
  }

  public function createdBy(): BelongsTo
  {
    return $this->belongsTo(User::class, 'created_by_user_id');
  }

  public function isPromotionActive(): bool
  {
    if ($this->promotional_price === null) {
      return false;
    }
    $now = now();
    if ($this->promotional_starts_at && $now->lt($this->promotional_starts_at)) {
      return false;
    }
    if ($this->promotional_ends_at && $now->gt($this->promotional_ends_at)) {
      return false;
    }

    return true;
  }
}
