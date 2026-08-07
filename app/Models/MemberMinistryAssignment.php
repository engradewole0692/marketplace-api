<?php

declare(strict_types=1);

namespace App\Models;

use App\Modules\Cms\Models\CmsMinistry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class MemberMinistryAssignment extends Model
{
  protected $fillable = [
    'uuid',
    'member_id',
    'ministry_id',
    'role',
    'department',
    'team',
    'is_primary',
    'assigned_at',
    'assigned_by',
    'mentor_user_id',
    'leader_user_id',
    'status',
  ];

  protected static function booted(): void
  {
    static::creating(function (MemberMinistryAssignment $assignment): void {
      if (empty($assignment->uuid)) {
        $assignment->uuid = (string) Str::uuid();
      }
    });
  }

  protected function casts(): array
  {
    return [
      'is_primary' => 'boolean',
      'assigned_at' => 'datetime',
    ];
  }

  public function member(): BelongsTo
  {
    return $this->belongsTo(Member::class);
  }

  public function ministry(): BelongsTo
  {
    return $this->belongsTo(CmsMinistry::class, 'ministry_id');
  }

  public function assigner(): BelongsTo
  {
    return $this->belongsTo(User::class, 'assigned_by');
  }

  public function mentor(): BelongsTo
  {
    return $this->belongsTo(User::class, 'mentor_user_id');
  }

  public function leader(): BelongsTo
  {
    return $this->belongsTo(User::class, 'leader_user_id');
  }
}
