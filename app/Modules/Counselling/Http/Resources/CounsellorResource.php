<?php

declare(strict_types=1);

namespace App\Modules\Counselling\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Modules\Counselling\Models\Counsellor */
final class CounsellorResource extends JsonResource
{
  public function toArray(Request $request): array
  {
    return [
      'id' => $this->uuid,
      'display_name' => $this->display_name,
      'slug' => $this->slug,
      'biography' => $this->biography,
      'specializations' => $this->specializations ?? [],
      'languages' => $this->languages ?? [],
      'google_meet_link' => $this->google_meet_link,
      'zoom_link' => $this->zoom_link,
      'teams_link' => $this->teams_link,
      'max_daily_sessions' => (int) $this->max_daily_sessions,
      'is_active' => (bool) $this->is_active,
      'sort_order' => (int) $this->sort_order,
      'metadata' => $this->metadata,
      'photo_media_id' => $this->whenLoaded('photoMedia', fn () => $this->photoMedia?->uuid),
      'photo_url' => $this->whenLoaded('photoMedia', fn () => $this->photoMedia?->url()),
      'user_id' => $this->whenLoaded('user', fn () => $this->user?->uuid),
      'open_cases_count' => $this->when(isset($this->open_cases_count), fn () => (int) $this->open_cases_count),
      'created_at' => $this->created_at?->toIso8601String(),
      'updated_at' => $this->updated_at?->toIso8601String(),
    ];
  }
}
