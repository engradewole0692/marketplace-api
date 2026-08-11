<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\MemberApprovalStatus;
use App\Enums\MemberStatus;
use App\Modules\Cms\Models\CmsCountry;
use App\Modules\Cms\Models\CmsMinistry;
use Database\Factories\MemberFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Member extends Model
{
  /** @use HasFactory<MemberFactory> */
  use HasFactory;
  use SoftDeletes;

  /**
   * @var list<string>
   */
  protected $fillable = [
    'uuid',
    'membership_number',
    'application_number',
    'application_tracking_token',
    'user_id',
    'photo_path',
    'photo_media_id',
    'title',
    'first_name',
    'middle_name',
    'last_name',
    'display_name',
    'gender',
    'date_of_birth',
    'phone',
    'alternate_phone',
    'email',
    'occupation',
    'organization',
    'marketplace_sector',
    'skills',
    'languages',
    'biography',
    'country_id',
    'city',
    'state',
    'region_id',
    'ministry_id',
    'preferred_ministry_id',
    'status',
    'approval_status',
    'joined_at',
    'activated_at',
    'orientation_completed_at',
    'created_by',
    'updated_by',
    'profession',
    'church_name',
    'church_address',
    'years_of_experience',
    'years_in_faith',
    'ministry_interests',
    'gifts',
    'references',
    'education',
    'availability',
    'interview_notes',
    'onboarding_notes',
  ];

  protected static function booted(): void
  {
    static::creating(function (Member $member): void {
      if (empty($member->uuid)) {
        $member->uuid = (string) Str::uuid();
      }

      $member->syncDisplayName();
    });

    static::updating(function (Member $member): void {
      if ($member->isDirty(['first_name', 'middle_name', 'last_name', 'display_name'])) {
        $member->syncDisplayName();
      }
    });
  }

  /**
   * @return array<string, string>
   */
  protected function casts(): array
  {
    return [
      'date_of_birth' => 'date',
      'joined_at' => 'date',
      'skills' => 'array',
      'languages' => 'array',
      'ministry_interests' => 'array',
      'gifts' => 'array',
      'references' => 'array',
      'status' => MemberStatus::class,
      'approval_status' => MemberApprovalStatus::class,
      'activated_at' => 'datetime',
      'orientation_completed_at' => 'datetime',
    ];
  }

  public function getRouteKeyName(): string
  {
    return 'uuid';
  }

  /**
   * Accept either UUID or numeric primary key for route model binding.
   */
  public function resolveRouteBinding($value, $field = null): ?Model
  {
    $field ??= $this->getRouteKeyName();

    $query = $this->newQuery();

    if (is_numeric($value) && ! str_contains((string) $value, '-')) {
      return $query->where($this->getKeyName(), $value)->first();
    }

    return $query->where($field, $value)->first();
  }

  public function syncDisplayName(): void
  {
    if (empty($this->display_name)) {
      $this->display_name = trim(
        implode(' ', array_filter([$this->first_name, $this->middle_name, $this->last_name])),
      ) ?: null;
    }
  }

  public function fullName(): string
  {
    return $this->display_name
      ?? trim(implode(' ', array_filter([$this->first_name, $this->middle_name, $this->last_name])))
      ?: $this->membership_number;
  }

  public function user(): BelongsTo
  {
    return $this->belongsTo(User::class);
  }

  public function creator(): BelongsTo
  {
    return $this->belongsTo(User::class, 'created_by');
  }

  public function updater(): BelongsTo
  {
    return $this->belongsTo(User::class, 'updated_by');
  }

  public function country(): BelongsTo
  {
    return $this->belongsTo(CmsCountry::class, 'country_id');
  }

  public function ministry(): BelongsTo
  {
    return $this->belongsTo(CmsMinistry::class, 'ministry_id');
  }

  public function preferredMinistry(): BelongsTo
  {
    return $this->belongsTo(CmsMinistry::class, 'preferred_ministry_id');
  }

  public function ministryAssignments(): HasMany
  {
    return $this->hasMany(MemberMinistryAssignment::class);
  }

  public function interviews(): HasMany
  {
    return $this->hasMany(MemberInterview::class);
  }

  public function notificationQueue(): HasMany
  {
    return $this->hasMany(MemberNotificationQueue::class);
  }

  public function onboardingChecklist(): HasMany
  {
    return $this->hasMany(MemberOnboardingChecklistItem::class);
  }

  public function isActiveMember(): bool
  {
    $status = $this->status instanceof MemberStatus ? $this->status : MemberStatus::from((string) $this->status);

    return $status === MemberStatus::Active && $this->user_id !== null;
  }

  /** Approved membership record — used for member pricing (not IAM roles). */
  public function qualifiesForMemberPricing(): bool
  {
    if ($this->user_id === null) {
      return false;
    }

    $approval = $this->approval_status instanceof MemberApprovalStatus
      ? $this->approval_status
      : MemberApprovalStatus::tryFrom((string) $this->approval_status);

    return $approval === MemberApprovalStatus::Approved;
  }

  public function region(): BelongsTo
  {
    return $this->belongsTo(Region::class, 'region_id');
  }

  public function contacts(): HasMany
  {
    return $this->hasMany(MemberContact::class);
  }

  public function addresses(): HasMany
  {
    return $this->hasMany(MemberAddress::class);
  }

  public function notes(): HasMany
  {
    return $this->hasMany(MemberNote::class);
  }

  public function documents(): HasMany
  {
    return $this->hasMany(MemberDocument::class);
  }

  public function timelines(): HasMany
  {
    return $this->hasMany(MemberTimeline::class);
  }

  public function statusTransitions(): HasMany
  {
    return $this->hasMany(MemberStatusTransition::class);
  }

  public function tags(): BelongsToMany
  {
    return $this->belongsToMany(MemberTag::class, 'member_tag_member')->withTimestamps();
  }

  public function photoMedia(): BelongsTo
  {
    return $this->belongsTo(\App\Modules\Cms\Models\CmsMedia::class, 'photo_media_id');
  }

  public function photoUrl(): ?string
  {
    $media = $this->relationLoaded('photoMedia')
      ? $this->photoMedia
      : ($this->photo_media_id ? $this->photoMedia()->first() : null);

    if ($media !== null) {
      return $media->url();
    }

    if ($this->photo_path === null) {
      return null;
    }

    return asset('storage/'.$this->photo_path);
  }
}
