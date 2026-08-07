<?php

declare(strict_types=1);

namespace App\Modules\Events\Http\Resources;

use App\Modules\Events\Models\EventExportJob;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin EventExportJob */
final class EventExportJobResource extends JsonResource
{
  public function toArray(Request $request): array
  {
    return [
      'id' => $this->uuid,
      'event_id' => $this->event?->uuid,
      'export_type' => $this->export_type,
      'format' => $this->format,
      'filters' => $this->filters,
      'status' => $this->status instanceof \BackedEnum ? $this->status->value : $this->status,
      'file_url' => $this->file_url,
      'started_at' => $this->started_at?->toIso8601String(),
      'completed_at' => $this->completed_at?->toIso8601String(),
      'failed_at' => $this->failed_at?->toIso8601String(),
      'failure_reason' => $this->failure_reason,
      'created_at' => $this->created_at?->toIso8601String(),
    ];
  }
}
