<?php

declare(strict_types=1);

namespace App\Modules\Communications\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Modules\Communications\Models\CommunicationSetting */
final class CommunicationSettingResource extends JsonResource
{
  public function toArray(Request $request): array
  {
    return [
      'id' => (string) $this->id,
      'ministry_email' => $this->ministry_email,
      'reply_to_email' => $this->reply_to_email,
      'reply_to_name' => $this->reply_to_name,
      'from_name' => $this->from_name,
      'branding' => $this->branding ?? [],
      'metadata' => $this->metadata ?? [],
      'updated_at' => $this->updated_at?->toIso8601String(),
    ];
  }
}
