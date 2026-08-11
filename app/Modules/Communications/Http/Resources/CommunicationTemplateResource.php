<?php

declare(strict_types=1);

namespace App\Modules\Communications\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Modules\Communications\Models\CommunicationTemplate */
final class CommunicationTemplateResource extends JsonResource
{
  public function toArray(Request $request): array
  {
    return [
      'id' => $this->uuid,
      'slug' => $this->slug,
      'name' => $this->name,
      'section' => $this->section,
      'event_key' => $this->event_key,
      'description' => $this->description,
      'subject' => $this->subject,
      'html_body' => $this->html_body,
      'text_body' => $this->text_body,
      'available_variables' => $this->available_variables ?? [],
      'sample_variables' => $this->sample_variables ?? [],
      'is_active' => (bool) $this->is_active,
      'is_system' => (bool) $this->is_system,
      'updated_at' => $this->updated_at?->toIso8601String(),
    ];
  }
}
