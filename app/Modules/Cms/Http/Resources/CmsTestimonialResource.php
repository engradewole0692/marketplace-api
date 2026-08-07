<?php

declare(strict_types=1);

namespace App\Modules\Cms\Http\Resources;

use App\Modules\Cms\Models\CmsTestimonial;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin CmsTestimonial */
final class CmsTestimonialResource extends JsonResource
{
  public function toArray(Request $request): array
  {
    $isAdmin = $request->user() !== null && str_contains($request->path(), 'cms/');

    return [
      'id' => $this->uuid,
      'author_name' => $this->displayName(),
      'author_title' => $this->author_title,
      'author_location' => $this->author_location,
      'quote' => $this->quote,
      'status' => $this->status?->value ?? 'approved',
      'category' => $this->category,
      'is_anonymous' => (bool) $this->is_anonymous,
      'submitter_type' => $this->submitter_type,
      'photo_media_id' => $this->photoMedia?->uuid,
      'photo_url' => $this->photoMedia?->url(),
      'video_media_id' => $this->videoMedia?->uuid,
      'video_url' => $this->videoMedia?->url(),
      'is_featured' => (bool) $this->is_featured,
      'is_active' => (bool) $this->is_active,
      'show_on_homepage' => (bool) $this->show_on_homepage,
      'show_on_page' => (bool) $this->show_on_page,
      'sort_order' => $this->sort_order,
      'submitter_email' => $this->when($isAdmin, $this->submitter_email),
      'submitter_phone' => $this->when($isAdmin, $this->submitter_phone),
      'rejection_reason' => $this->when($isAdmin, $this->rejection_reason),
      'moderated_at' => $this->when($isAdmin, $this->moderated_at?->toIso8601String()),
      'moderated_by' => $this->when($isAdmin && $this->relationLoaded('moderator'), $this->moderator?->display_name),
      'created_at' => $this->created_at?->toIso8601String(),
      'updated_at' => $this->updated_at?->toIso8601String(),
    ];
  }
}
