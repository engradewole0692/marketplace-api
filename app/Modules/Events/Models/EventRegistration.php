<?php

declare(strict_types=1);

namespace App\Modules\Events\Models;

use App\Models\Member;
use App\Models\User;
use App\Modules\Events\Enums\RegistrationStatus;
use App\Modules\Events\Support\HasEventUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class EventRegistration extends Model
{
  use HasEventUuid;
  use SoftDeletes;

  /**
   * @var list<string>
   */
  protected $fillable = [
    'uuid',
    'event_id',
    'member_id',
    'guest_name',
    'guest_email',
    'guest_phone',
    'registration_number',
    'status',
    'source',
    'emergency_contact_name',
    'emergency_contact_relationship',
    'emergency_contact_phone',
    'arrival_date',
    'departure_date',
    'accommodation_required',
    'airport_pickup_required',
    'dietary_requirements',
    'medical_notes',
    'volunteer_interest',
    'prayer_requests',
    'additional_notes',
    'consent_accepted',
    'consent_accepted_at',
    'submitted_at',
    'approved_at',
    'cancelled_at',
    'approved_by_user_id',
    'cancelled_by_user_id',
    'created_by_user_id',
    'updated_by_user_id',
    'deleted_by_user_id',
    'metadata',
  ];

  /**
   * @return array<string, string>
   */
  protected function casts(): array
  {
    return [
      'status' => RegistrationStatus::class,
      'arrival_date' => 'date',
      'departure_date' => 'date',
      'accommodation_required' => 'boolean',
      'airport_pickup_required' => 'boolean',
      'volunteer_interest' => 'boolean',
      'consent_accepted' => 'boolean',
      'consent_accepted_at' => 'datetime',
      'submitted_at' => 'datetime',
      'approved_at' => 'datetime',
      'cancelled_at' => 'datetime',
      'metadata' => 'array',
    ];
  }

  public function getRouteKeyName(): string
  {
    return 'uuid';
  }

  public function scopeForEvent(Builder $query, int $eventId): Builder
  {
    return $query->where('event_id', $eventId);
  }

  public function scopeStatus(Builder $query, RegistrationStatus|string $status): Builder
  {
    return $query->where('status', $status instanceof RegistrationStatus ? $status->value : $status);
  }

  public function event(): BelongsTo
  {
    return $this->belongsTo(Event::class);
  }

  public function member(): BelongsTo
  {
    return $this->belongsTo(Member::class);
  }

  public function answers(): HasMany
  {
    return $this->hasMany(EventRegistrationQuestionAnswer::class, 'registration_id');
  }

  public function statusTransitions(): HasMany
  {
    return $this->hasMany(EventRegistrationStatusTransition::class, 'registration_id');
  }

  public function timelines(): HasMany
  {
    return $this->hasMany(EventRegistrationTimeline::class, 'registration_id');
  }

  public function auditLogs(): HasMany
  {
    return $this->hasMany(EventRegistrationAuditLog::class, 'registration_id');
  }

  public function checkInToken(): HasOne
  {
    return $this->hasOne(EventCheckInToken::class, 'registration_id');
  }

  public function checkIns(): HasMany
  {
    return $this->hasMany(EventCheckIn::class, 'registration_id');
  }

  public function attendanceHistories(): HasMany
  {
    return $this->hasMany(EventAttendanceHistory::class, 'registration_id');
  }

  public function certificates(): HasMany
  {
    return $this->hasMany(EventCertificateIssuance::class, 'registration_id');
  }

  public function volunteerAssignments(): HasMany
  {
    return $this->hasMany(EventVolunteerAssignment::class, 'registration_id');
  }

  public function payments(): HasMany
  {
    return $this->hasMany(EventRegistrationPayment::class, 'registration_id');
  }

  public function approver(): BelongsTo
  {
    return $this->belongsTo(User::class, 'approved_by_user_id');
  }

  public function canceller(): BelongsTo
  {
    return $this->belongsTo(User::class, 'cancelled_by_user_id');
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

  public function contactName(): ?string
  {
    return $this->member?->fullName() ?? $this->guest_name;
  }

  public function contactEmail(): ?string
  {
    return $this->member?->email ?? $this->guest_email;
  }

  public function contactPhone(): ?string
  {
    return $this->member?->phone ?? $this->guest_phone;
  }
}
