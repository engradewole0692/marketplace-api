<?php

declare(strict_types=1);

namespace App\Modules\Events\Http\Resources;

use App\Modules\Cms\Http\Resources\CmsCountryResource;
use App\Modules\Cms\Http\Resources\CmsMinistryResource;
use App\Modules\Events\Models\Event;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Event */
final class EventResource extends JsonResource
{
  public function toArray(Request $request): array
  {
    return [
      'id' => $this->uuid,
      'ministry' => $this->whenLoaded('ministry', fn () => new CmsMinistryResource($this->ministry)),
      'category' => $this->whenLoaded('category', fn () => new EventCategoryResource($this->category)),
      'venue' => $this->whenLoaded('venue', fn () => new VenueResource($this->venue)),
      'country' => $this->whenLoaded('country', fn () => new CmsCountryResource($this->country)),
      'region_id' => $this->region_id,
      'title' => $this->title,
      'slug' => $this->slug,
      'theme' => $this->theme,
      'theme_scripture' => $this->theme_scripture,
      'theme_color' => $this->theme_color,
      'banner_url' => $this->banner_url,
      'summary' => $this->summary,
      'description' => $this->description,
      'starts_at' => $this->starts_at?->toIso8601String(),
      'ends_at' => $this->ends_at?->toIso8601String(),
      'timezone' => $this->timezone,
      'registration_opens_at' => $this->registration_opens_at?->toIso8601String(),
      'registration_deadline' => $this->registration_deadline?->toIso8601String(),
      'capacity' => $this->capacity,
      'check_in_enabled' => $this->check_in_enabled,
      'certificate_enabled' => $this->certificate_enabled,
      'attendance_required' => $this->attendance_required,
      'is_featured' => (bool) $this->is_featured,
      'is_paid' => (bool) $this->is_paid,
      'payment_required' => (bool) $this->payment_required,
      'price' => $this->price !== null ? (float) $this->price : null,
      'currency' => $this->currency,
      'seo_title' => $this->seo_title,
      'seo_description' => $this->seo_description,
      'announcement' => $this->announcement,
      'visibility' => $this->visibility instanceof \BackedEnum ? $this->visibility->value : $this->visibility,
      'status' => $this->status instanceof \BackedEnum ? $this->status->value : $this->status,
      'is_registration_open' => $this->is_registration_open,
      'is_full' => $this->is_full,
      'registrations_count' => $this->when(
        $this->relationLoaded('registrations') || array_key_exists('registrations_count', $this->getAttributes()),
        fn () => $this->registrations_count ?? $this->registrations->count(),
      ),
      'speakers' => SpeakerResource::collection($this->whenLoaded('speakers')),
      'sessions' => EventSessionResource::collection($this->whenLoaded('sessions')),
      'gallery_items' => EventGalleryItemResource::collection($this->whenLoaded('galleryItems')),
      'resources' => EventResourceItemResource::collection($this->whenLoaded('resources')),
      'faqs' => EventFaqResource::collection($this->whenLoaded('faqs')),
      'sponsors' => EventSponsorResource::collection($this->whenLoaded('sponsors')),
      'registration_questions' => EventRegistrationQuestionResource::collection($this->whenLoaded('registrationQuestions')),
      'published_at' => $this->published_at?->toIso8601String(),
      'created_at' => $this->created_at?->toIso8601String(),
      'updated_at' => $this->updated_at?->toIso8601String(),
    ];
  }
}
