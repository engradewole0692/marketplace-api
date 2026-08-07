<?php

declare(strict_types=1);

namespace App\Modules\Events\Http\Resources;

use App\Modules\Events\Models\Speaker;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Speaker */
final class SpeakerResource extends JsonResource
{
  public function toArray(Request $request): array
  {
    return [
      'id' => $this->uuid,
      'name' => $this->name,
      'slug' => $this->slug,
      'title' => $this->title,
      'organization' => $this->organization,
      'bio' => $this->bio,
      'photo_url' => $this->photo_url,
      'email' => $this->email,
      'phone' => $this->phone,
      'website_url' => $this->website_url,
      'status' => $this->status,
      'created_at' => $this->created_at?->toIso8601String(),
      'updated_at' => $this->updated_at?->toIso8601String(),
    ];
  }
}
