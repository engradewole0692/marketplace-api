<?php

declare(strict_types=1);

namespace App\Modules\Cms\Http\Resources;

use App\Modules\Cms\Models\CmsMinistry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin CmsMinistry */
final class CmsMinistryResource extends JsonResource
{
  public function toArray(Request $request): array
  {
    return [
      'id' => $this->uuid,
      'name' => $this->name,
      'slug' => $this->slug,
      'icon' => $this->icon,
      'color' => $this->color,
      'tagline' => $this->tagline,
      'summary' => $this->summary,
      'about' => $this->about,
      'mission' => $this->mission,
      'vision' => $this->vision,
      'purposes' => $this->purposes,
      'programs' => $this->programs,
      'content' => $this->content,
      'leaders' => CmsLeadershipResource::collection($this->whenLoaded('leaders')),
      'hero_media_id' => $this->heroMedia?->uuid,
      'logo_media_id' => $this->logoMedia?->uuid,
      'banner_media_id' => $this->bannerMedia?->uuid,
      'cover_media_id' => $this->coverMedia?->uuid,
      'image_url' => $this->heroMedia?->url(),
      'logo_url' => $this->logoMedia?->url(),
      'banner_url' => $this->bannerMedia?->url(),
      'cover_url' => $this->coverMedia?->url(),
      'visibility' => $this->visibility,
      'operational_status' => $this->operational_status,
      'leader_member_id' => $this->leader_member_id,
      'assistant_leader_member_id' => $this->assistant_leader_member_id,
      'leader' => $this->whenLoaded('leaderMember', fn () => $this->leaderMember ? [
        'id' => $this->leaderMember->id,
        'name' => $this->leaderMember->fullName(),
        'email' => $this->leaderMember->email,
      ] : null),
      'assistant_leader' => $this->whenLoaded('assistantLeaderMember', fn () => $this->assistantLeaderMember ? [
        'id' => $this->assistantLeaderMember->id,
        'name' => $this->assistantLeaderMember->fullName(),
        'email' => $this->assistantLeaderMember->email,
      ] : null),
      'whatsapp_link' => $this->whatsapp_link,
      'telegram_link' => $this->telegram_link,
      'signal_link' => $this->signal_link,
      'country_availability' => $this->country_availability,
      'is_active' => $this->is_active,
      'sort_order' => $this->sort_order,
    ];
  }
}
