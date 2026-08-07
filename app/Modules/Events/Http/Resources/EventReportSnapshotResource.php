<?php

declare(strict_types=1);

namespace App\Modules\Events\Http\Resources;

use App\Modules\Events\Models\EventReportSnapshot;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin EventReportSnapshot */
final class EventReportSnapshotResource extends JsonResource
{
  public function toArray(Request $request): array
  {
    return [
      'id' => $this->uuid,
      'event_id' => $this->event?->uuid,
      'report_type' => $this->report_type,
      'filters' => $this->filters,
      'metrics' => $this->metrics,
      'generated_at' => $this->generated_at?->toIso8601String(),
    ];
  }
}
