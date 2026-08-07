<?php

declare(strict_types=1);

namespace App\Modules\Events\Http\Resources;

use App\Modules\Events\Models\EventCertificateTemplate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin EventCertificateTemplate */
final class EventCertificateTemplateResource extends JsonResource
{
  public function toArray(Request $request): array
  {
    return [
      'id' => $this->uuid,
      'event_id' => $this->event?->uuid,
      'name' => $this->name,
      'slug' => $this->slug,
      'html_body' => $this->html_body,
      'background_media_id' => $this->background_media_id,
      'is_active' => (bool) $this->is_active,
      'sort_order' => (int) $this->sort_order,
      'created_at' => $this->created_at?->toIso8601String(),
      'updated_at' => $this->updated_at?->toIso8601String(),
    ];
  }
}
