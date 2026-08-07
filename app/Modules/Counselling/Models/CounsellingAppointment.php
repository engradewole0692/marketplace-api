<?php

declare(strict_types=1);

namespace App\Modules\Counselling\Models;

use App\Modules\Counselling\Enums\AppointmentStatus;
use App\Modules\Counselling\Enums\ServiceFormat;
use App\Modules\Counselling\Support\HasCounsellingUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class CounsellingAppointment extends Model
{
  use HasCounsellingUuid;
  use SoftDeletes;

  protected $table = 'counselling_appointments';

  /** @var list<string> */
  protected $fillable = [
    'uuid',
    'case_id',
    'counsellor_id',
    'session_number',
    'status',
    'format',
    'starts_at',
    'ends_at',
    'timezone',
    'meeting_link',
    'meeting_platform',
    'location',
    'reminder_sent_at',
    'attended_at',
    'notes',
    'metadata',
  ];

  protected function casts(): array
  {
    return [
      'session_number' => 'integer',
      'status' => AppointmentStatus::class,
      'format' => ServiceFormat::class,
      'starts_at' => 'datetime',
      'ends_at' => 'datetime',
      'reminder_sent_at' => 'datetime',
      'attended_at' => 'datetime',
      'metadata' => 'array',
    ];
  }

  public function getRouteKeyName(): string
  {
    return 'uuid';
  }

  public function case(): BelongsTo
  {
    return $this->belongsTo(CounsellingCase::class, 'case_id');
  }

  public function counsellor(): BelongsTo
  {
    return $this->belongsTo(Counsellor::class, 'counsellor_id');
  }
}
