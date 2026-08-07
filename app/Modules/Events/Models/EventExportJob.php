<?php

declare(strict_types=1);

namespace App\Modules\Events\Models;

use App\Models\User;
use App\Modules\Events\Enums\ExportStatus;
use App\Modules\Events\Support\HasEventUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class EventExportJob extends Model
{
  use HasEventUuid;

  protected $table = 'event_export_jobs';

  /**
   * @var list<string>
   */
  protected $fillable = [
    'uuid',
    'event_id',
    'export_type',
    'format',
    'filters',
    'status',
    'file_path',
    'disk',
    'requested_by_user_id',
    'started_at',
    'completed_at',
    'failed_at',
    'failure_reason',
    'metadata',
  ];

  /**
   * @var list<string>
   */
  protected $appends = [
    'file_url',
  ];

  /**
   * @return array<string, string>
   */
  protected function casts(): array
  {
    return [
      'filters' => 'array',
      'status' => ExportStatus::class,
      'started_at' => 'datetime',
      'completed_at' => 'datetime',
      'failed_at' => 'datetime',
      'metadata' => 'array',
    ];
  }

  public function getRouteKeyName(): string
  {
    return 'uuid';
  }

  public function event(): BelongsTo
  {
    return $this->belongsTo(Event::class);
  }

  public function requester(): BelongsTo
  {
    return $this->belongsTo(User::class, 'requested_by_user_id');
  }

  public function getFileUrlAttribute(): ?string
  {
    return $this->file_path ? Storage::disk($this->disk ?? 'public')->url($this->file_path) : null;
  }
}
