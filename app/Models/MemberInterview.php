<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\MemberInterviewStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class MemberInterview extends Model
{
  use SoftDeletes;

  protected $fillable = [
    'uuid',
    'member_id',
    'parent_interview_id',
    'status',
    'interview_type',
    'scheduled_date',
    'scheduled_time',
    'duration_minutes',
    'timezone',
    'interviewer_id',
    'external_interviewer_name',
    'meeting_link',
    'meeting_platform',
    'meeting_password',
    'physical_location',
    'venue',
    'remarks',
    'instructions',
    'result',
    'confirmation_token',
    'invitation_sent_at',
    'confirmed_at',
    'awaiting_review_notified_at',
    'created_by',
    'updated_by',
  ];

  protected static function booted(): void
  {
    static::creating(function (MemberInterview $interview): void {
      if (empty($interview->uuid)) {
        $interview->uuid = (string) Str::uuid();
      }
    });
  }

  protected function casts(): array
  {
    return [
      'scheduled_date' => 'date',
      'invitation_sent_at' => 'datetime',
      'confirmed_at' => 'datetime',
      'awaiting_review_notified_at' => 'datetime',
      'status' => MemberInterviewStatus::class,
    ];
  }

  public function getRouteKeyName(): string
  {
    return 'uuid';
  }

  public function member(): BelongsTo
  {
    return $this->belongsTo(Member::class);
  }

  public function interviewer(): BelongsTo
  {
    return $this->belongsTo(User::class, 'interviewer_id');
  }

  public function interviewers(): BelongsToMany
  {
    return $this->belongsToMany(User::class, 'member_interview_interviewers')
      ->withPivot(['is_primary'])
      ->withTimestamps();
  }

  public function parentInterview(): BelongsTo
  {
    return $this->belongsTo(self::class, 'parent_interview_id');
  }

  public function creator(): BelongsTo
  {
    return $this->belongsTo(User::class, 'created_by');
  }
}
