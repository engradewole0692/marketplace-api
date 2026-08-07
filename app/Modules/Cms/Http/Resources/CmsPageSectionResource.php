<?php

declare(strict_types=1);

namespace App\Modules\Cms\Http\Resources;

use App\Modules\Cms\Models\CmsPageSection;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin CmsPageSection */
final class CmsPageSectionResource extends JsonResource
{
  public function toArray(Request $request): array
  {
    $isAdmin = $request->user() !== null && str_contains($request->path(), 'cms/');

    return [
      'id' => $this->uuid,
      'section_key' => $this->section_key,
      'section_type' => $this->section_type,
      'title' => $this->title,
      'content' => $this->content,
      'draft_content' => $this->when($isAdmin, fn () => $this->draft_content ?? $this->content),
      'is_active' => (bool) $this->is_active,
      'status' => $this->when($isAdmin, fn () => $this->status ?? 'published'),
      'sort_order' => $this->sort_order,
      'published_at' => $this->when($isAdmin, fn () => $this->published_at?->toIso8601String()),
      'versions' => $this->when($isAdmin && $this->relationLoaded('versions'), fn () => $this->versions->map(fn ($version) => [
        'id' => $version->uuid,
        'version_number' => $version->version_number,
        'status' => $version->status,
        'change_summary' => $version->change_summary,
        'created_at' => $version->created_at?->toIso8601String(),
      ])->values()),
    ];
  }
}
