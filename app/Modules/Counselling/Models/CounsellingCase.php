<?php

declare(strict_types=1);

namespace App\Modules\Counselling\Models;

use App\Models\Member;
use App\Models\User;
use App\Modules\Cms\Models\CmsFormSubmission;
use App\Modules\Counselling\Enums\CaseStatus;
use App\Modules\Counselling\Enums\ClientType;
use App\Modules\Counselling\Enums\ServiceFormat;
use App\Modules\Counselling\Support\HasCounsellingUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class CounsellingCase extends Model
{
  use HasCounsellingUuid;
  use SoftDeletes;

  protected $table = 'counselling_cases';

  /** @var list<string> */
  protected $fillable = [
    'uuid',
    'case_number',
    'service_id',
    'category_id',
    'user_id',
    'member_id',
    'counsellor_id',
    'source_submission_id',
    'client_type',
    'status',
    'preferred_format',
    'client_name',
    'client_email',
    'client_phone',
    'client_country',
    'client_gender',
    'who_is_this_for',
    'preferred_counsellor_gender',
    'reason',
    'prayer_request',
    'preferred_at',
    'timezone',
    'session_count',
    'allow_reschedule',
    'allow_cancel',
    'assigned_at',
    'scheduled_at',
    'completed_at',
    'cancelled_at',
    'cancellation_reason',
    'member_snapshot',
    'metadata',
    'created_by_user_id',
  ];

  protected function casts(): array
  {
    return [
      'client_type' => ClientType::class,
      'status' => CaseStatus::class,
      'preferred_format' => ServiceFormat::class,
      'preferred_at' => 'datetime',
      'session_count' => 'integer',
      'allow_reschedule' => 'boolean',
      'allow_cancel' => 'boolean',
      'assigned_at' => 'datetime',
      'scheduled_at' => 'datetime',
      'completed_at' => 'datetime',
      'cancelled_at' => 'datetime',
      'member_snapshot' => 'array',
      'metadata' => 'array',
    ];
  }

  public function getRouteKeyName(): string
  {
    return 'uuid';
  }

  public function service(): BelongsTo
  {
    return $this->belongsTo(CounsellingService::class, 'service_id');
  }

  public function category(): BelongsTo
  {
    return $this->belongsTo(CounsellingCategory::class, 'category_id');
  }

  public function user(): BelongsTo
  {
    return $this->belongsTo(User::class);
  }

  public function member(): BelongsTo
  {
    return $this->belongsTo(Member::class);
  }

  public function counsellor(): BelongsTo
  {
    return $this->belongsTo(Counsellor::class, 'counsellor_id');
  }

  public function sourceSubmission(): BelongsTo
  {
    return $this->belongsTo(CmsFormSubmission::class, 'source_submission_id');
  }

  public function createdBy(): BelongsTo
  {
    return $this->belongsTo(User::class, 'created_by_user_id');
  }

  public function appointments(): HasMany
  {
    return $this->hasMany(CounsellingAppointment::class, 'case_id');
  }

  public function payments(): HasMany
  {
    return $this->hasMany(CounsellingPayment::class, 'case_id');
  }

  public function latestPayment(): HasOne
  {
    return $this->hasOne(CounsellingPayment::class, 'case_id')->latestOfMany();
  }

  public function documents(): HasMany
  {
    return $this->hasMany(CounsellingDocument::class, 'case_id');
  }

  public function notes(): HasMany
  {
    return $this->hasMany(CounsellingNote::class, 'case_id');
  }

  public function messages(): HasMany
  {
    return $this->hasMany(CounsellingMessage::class, 'case_id');
  }

  public function events(): HasMany
  {
    return $this->hasMany(CounsellingCaseEvent::class, 'case_id');
  }

  public function feedback(): HasOne
  {
    return $this->hasOne(CounsellingFeedback::class, 'case_id');
  }

  public function nextAppointment(): HasOne
  {
    return $this->hasOne(CounsellingAppointment::class, 'case_id')
      ->whereIn('status', ['scheduled', 'confirmed'])
      ->where('starts_at', '>=', now())
      ->orderBy('starts_at');
  }
}
