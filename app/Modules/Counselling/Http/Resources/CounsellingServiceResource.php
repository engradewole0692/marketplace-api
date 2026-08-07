<?php

declare(strict_types=1);

namespace App\Modules\Counselling\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Modules\Counselling\Models\CounsellingService */
final class CounsellingServiceResource extends JsonResource
{
  public function toArray(Request $request): array
  {
    return [
      'id' => $this->uuid,
      'title' => $this->title,
      'slug' => $this->slug,
      'description' => $this->description,
      'short_description' => $this->short_description,
      'icon' => $this->icon,
      'duration_minutes' => (int) $this->duration_minutes,
      'format' => $this->format instanceof \BackedEnum ? $this->format->value : $this->format,
      'google_meet_link' => $this->google_meet_link,
      'zoom_link' => $this->zoom_link,
      'teams_link' => $this->teams_link,
      'office_address' => $this->office_address,
      'maximum_sessions' => (int) $this->maximum_sessions,
      'requires_approval' => (bool) $this->requires_approval,
      'requires_payment' => $this->when($this->shouldExposePricing($request), (bool) $this->requires_payment),
      'is_free' => $this->when($this->shouldExposePricing($request), (bool) $this->is_free),
      'visitor_price' => $this->when(
        $this->shouldExposePricing($request),
        $this->visitor_price !== null ? (float) $this->visitor_price : null,
      ),
      'member_price' => $this->when(
        $this->shouldExposePricing($request),
        $this->member_price !== null ? (float) $this->member_price : null,
      ),
      'currency' => $this->when($this->shouldExposePricing($request), $this->currency),
      'is_visible' => (bool) $this->is_visible,
      'is_featured' => (bool) $this->is_featured,
      'sort_order' => (int) $this->sort_order,
      'seo_title' => $this->seo_title,
      'seo_description' => $this->seo_description,
      'status' => $this->status,
      'metadata' => $this->metadata,
      'banner_media_id' => $this->whenLoaded('bannerMedia', fn () => $this->bannerMedia?->uuid),
      'banner_url' => $this->whenLoaded('bannerMedia', fn () => $this->bannerMedia?->url()),
      'category' => $this->whenLoaded('category', fn () => $this->category
        ? CounsellingCategoryResource::make($this->category)
        : null),
      'category_id' => $this->whenLoaded('category', fn () => $this->category?->uuid),
      'created_at' => $this->created_at?->toIso8601String(),
      'updated_at' => $this->updated_at?->toIso8601String(),
    ];
  }

  private function shouldExposePricing(Request $request): bool
  {
    $user = $request->user('sanctum') ?? $request->user();
    if ($user === null) {
      return false;
    }

    return method_exists($user, 'hasAnyPermission')
      && $user->hasAnyPermission(['counselling.manage', 'counselling.view', 'counsellor.portal']);
  }
}
