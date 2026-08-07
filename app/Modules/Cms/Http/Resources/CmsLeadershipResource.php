<?php

declare(strict_types=1);

namespace App\Modules\Cms\Http\Resources;

use App\Modules\Cms\Models\CmsLeadershipProfile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin CmsLeadershipProfile */
final class CmsLeadershipResource extends JsonResource
{
  public function toArray(Request $request): array
  {
    return [
      'id' => $this->uuid,
      'name' => $this->name,
      'slug' => $this->slug,
      'role' => $this->role,
      'hierarchy_level' => $this->hierarchy_level,
      'category' => $this->category,
      'location' => $this->location,
      'state' => $this->state,
      'bio' => $this->bio,
      'photo_media_id' => $this->photoMedia?->uuid,
      'photo_url' => $this->photoMedia?->url(),
      'email' => $this->email,
      'phone' => $this->phone,
      'social_links' => $this->social_links,
      'term_start' => $this->term_start?->toDateString(),
      'term_end' => $this->term_end?->toDateString(),
      'visibility' => $this->visibility,
      'permissions' => $this->permissions,
      'member_id' => $this->member_id,
      'country' => $this->country?->slug,
      'ministry' => $this->ministry?->slug,
      'is_active' => $this->is_active,
      'sort_order' => $this->sort_order,
    ];
  }
}
