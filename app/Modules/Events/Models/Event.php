<?php

declare(strict_types=1);

namespace App\Modules\Events\Models;

use App\Models\Region;
use App\Models\User;
use App\Modules\Cms\Models\CmsCountry;
use App\Modules\Cms\Models\CmsMedia;
use App\Modules\Cms\Models\CmsMinistry;
use App\Modules\Events\Enums\EventStatus;
use App\Modules\Events\Enums\EventVisibility;
use App\Modules\Events\Enums\RegistrationStatus;
use App\Modules\Events\Support\HasEventUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Event extends Model
{
  use HasEventUuid;
  use SoftDeletes;

  /**
   * @var list<string>
   */
  protected $fillable = [
    'uuid',
    'ministry_id',
    'event_category_id',
    'venue_id',
    'country_id',
    'region_id',
    'title',
    'slug',
    'theme',
    'theme_scripture',
    'theme_color',
    'banner_media_id',
    'summary',
    'description',
    'starts_at',
    'ends_at',
    'timezone',
    'registration_opens_at',
    'registration_deadline',
    'capacity',
    'check_in_enabled',
    'certificate_enabled',
    'attendance_required',
    'visibility',
    'status',
    'published_at',
    'metadata',
    'is_featured',
    'is_paid',
    'payment_required',
    'price',
    'currency',
    'seo_title',
    'seo_description',
    'announcement',
    'certificate_template_id',
    'created_by_user_id',
    'updated_by_user_id',
    'deleted_by_user_id',
    'published_by_user_id',
  ];

  /**
   * @var list<string>
   */
  protected $appends = [
    'banner_url',
    'is_registration_open',
    'is_full',
  ];

  /**
   * @return array<string, string>
   */
  protected function casts(): array
  {
    return [
      'starts_at' => 'datetime',
      'ends_at' => 'datetime',
      'registration_opens_at' => 'datetime',
      'registration_deadline' => 'datetime',
      'published_at' => 'datetime',
      'capacity' => 'integer',
      'check_in_enabled' => 'boolean',
      'certificate_enabled' => 'boolean',
      'attendance_required' => 'boolean',
      'visibility' => EventVisibility::class,
      'status' => EventStatus::class,
      'metadata' => 'array',
      'is_featured' => 'boolean',
      'is_paid' => 'boolean',
      'payment_required' => 'boolean',
      'price' => 'decimal:2',
    ];
  }

  public function getRouteKeyName(): string
  {
    return 'uuid';
  }

  public function scopePublished(Builder $query): Builder
  {
    return $query
      ->whereNotNull('published_at')
      ->whereIn('status', [EventStatus::Published->value, EventStatus::Open->value]);
  }

  public function scopeVisible(Builder $query): Builder
  {
    return $query->where('visibility', EventVisibility::Public->value);
  }

  public function scopeUpcoming(Builder $query): Builder
  {
    return $query->where('starts_at', '>=', now());
  }

  public function ministry(): BelongsTo
  {
    return $this->belongsTo(CmsMinistry::class, 'ministry_id');
  }

  public function category(): BelongsTo
  {
    return $this->belongsTo(EventCategory::class, 'event_category_id');
  }

  public function venue(): BelongsTo
  {
    return $this->belongsTo(Venue::class);
  }

  public function country(): BelongsTo
  {
    return $this->belongsTo(CmsCountry::class, 'country_id');
  }

  public function region(): BelongsTo
  {
    return $this->belongsTo(Region::class, 'region_id');
  }

  public function banner(): BelongsTo
  {
    return $this->belongsTo(CmsMedia::class, 'banner_media_id');
  }

  public function speakers(): BelongsToMany
  {
    return $this->belongsToMany(Speaker::class)
      ->using(EventSpeaker::class)
      ->withPivot(['role', 'sort_order'])
      ->withTimestamps()
      ->orderBy('event_speaker.sort_order');
  }

  public function sessions(): HasMany
  {
    return $this->hasMany(EventSession::class);
  }

  public function galleryItems(): HasMany
  {
    return $this->hasMany(EventGalleryItem::class);
  }

  public function resources(): HasMany
  {
    return $this->hasMany(EventResource::class);
  }

  public function faqs(): HasMany
  {
    return $this->hasMany(EventFaq::class);
  }

  public function sponsors(): HasMany
  {
    return $this->hasMany(EventSponsor::class);
  }

  public function registrationFieldSettings(): HasMany
  {
    return $this->hasMany(EventRegistrationFieldSetting::class);
  }

  public function registrationQuestions(): HasMany
  {
    return $this->hasMany(EventRegistrationQuestion::class);
  }

  public function registrations(): HasMany
  {
    return $this->hasMany(EventRegistration::class);
  }

  public function checkInTokens(): HasMany
  {
    return $this->hasMany(EventCheckInToken::class);
  }

  public function checkIns(): HasMany
  {
    return $this->hasMany(EventCheckIn::class);
  }

  public function attendanceHistories(): HasMany
  {
    return $this->hasMany(EventAttendanceHistory::class);
  }

  public function certificates(): HasMany
  {
    return $this->hasMany(EventCertificateIssuance::class);
  }

  public function reportSnapshots(): HasMany
  {
    return $this->hasMany(EventReportSnapshot::class);
  }

  public function auditLogs(): HasMany
  {
    return $this->hasMany(EventAuditLog::class);
  }

  public function notificationTemplates(): HasMany
  {
    return $this->hasMany(EventNotificationTemplate::class);
  }

  public function notificationLogs(): HasMany
  {
    return $this->hasMany(EventNotificationLog::class);
  }

  public function exportJobs(): HasMany
  {
    return $this->hasMany(EventExportJob::class);
  }

  public function volunteerRoles(): HasMany
  {
    return $this->hasMany(EventVolunteerRole::class);
  }

  public function volunteerAssignments(): HasMany
  {
    return $this->hasMany(EventVolunteerAssignment::class);
  }

  public function coupons(): HasMany
  {
    return $this->hasMany(EventCoupon::class);
  }

  public function certificateTemplates(): HasMany
  {
    return $this->hasMany(EventCertificateTemplate::class);
  }

  public function certificateTemplate(): BelongsTo
  {
    return $this->belongsTo(EventCertificateTemplate::class, 'certificate_template_id');
  }

  public function creator(): BelongsTo
  {
    return $this->belongsTo(User::class, 'created_by_user_id');
  }

  public function updater(): BelongsTo
  {
    return $this->belongsTo(User::class, 'updated_by_user_id');
  }

  public function deleter(): BelongsTo
  {
    return $this->belongsTo(User::class, 'deleted_by_user_id');
  }

  public function publisher(): BelongsTo
  {
    return $this->belongsTo(User::class, 'published_by_user_id');
  }

  public function getBannerUrlAttribute(): ?string
  {
    return $this->banner?->url();
  }

  public function getIsRegistrationOpenAttribute(): bool
  {
    $status = $this->status instanceof EventStatus ? $this->status : EventStatus::from((string) $this->status);

    if (! $status->acceptsRegistrations()) {
      return false;
    }

    if ($this->registration_opens_at !== null && now()->lt($this->registration_opens_at)) {
      return false;
    }

    return $this->registration_deadline === null || now()->lte($this->registration_deadline);
  }

  public function getIsFullAttribute(): bool
  {
    if ($this->capacity === null) {
      return false;
    }

    return $this->registrations()
      ->whereIn('status', [
        RegistrationStatus::Submitted->value,
        RegistrationStatus::PendingReview->value,
        RegistrationStatus::Approved->value,
        RegistrationStatus::CheckedIn->value,
        RegistrationStatus::Attended->value,
      ])
      ->count() >= $this->capacity;
  }
}
