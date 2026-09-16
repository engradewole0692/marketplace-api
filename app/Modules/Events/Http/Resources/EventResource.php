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
      'category' => $this->whenLoaded('category', fn () => $this->category ? new EventCategoryResource($this->category) : null),
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
      'main_hall_capacity' => $this->main_hall_capacity,
      'overflow_capacity' => $this->overflow_capacity,
      'seating_policy' => $this->seating_policy ?: 'main_then_overflow',
      'default_grace_before_minutes' => $this->default_grace_before_minutes,
      'default_grace_after_minutes' => $this->default_grace_after_minutes,
      'check_in_enabled' => $this->check_in_enabled,
      'checkout_enabled' => $this->checkout_enabled ?? true,
      'certificate_enabled' => $this->certificate_enabled,
      'accommodation_enabled' => (bool) $this->accommodation_enabled,
      'transport_enabled' => (bool) $this->transport_enabled,
      'travel_assistance_enabled' => (bool) $this->travel_assistance_enabled,
      'attendance_required' => $this->attendance_required,
      'attendance_mode' => $this->attendance_mode instanceof \BackedEnum ? $this->attendance_mode->value : $this->attendance_mode,
      'days' => EventDayResource::collection($this->whenLoaded('days')),
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
      'registrations_count' => isset($this->registrations_count)
        ? (int) $this->registrations_count
        : ($this->relationLoaded('registrations') ? $this->registrations->count() : 0),
      'speakers' => SpeakerResource::collection($this->whenLoaded('speakers')),
      'sessions' => EventSessionResource::collection($this->whenLoaded('sessions')),
      'gallery_items' => EventGalleryItemResource::collection($this->whenLoaded('galleryItems')),
      'resources' => EventResourceItemResource::collection($this->whenLoaded('resources')),
      'faqs' => EventFaqResource::collection($this->whenLoaded('faqs')),
      'sponsors' => EventSponsorResource::collection($this->whenLoaded('sponsors')),
      'registration_questions' => EventRegistrationQuestionResource::collection($this->whenLoaded('registrationQuestions')),
      'registration_field_settings' => EventRegistrationFieldSettingResource::collection($this->whenLoaded('registrationFieldSettings')),
      'published_at' => $this->published_at?->toIso8601String(),
      'created_at' => $this->created_at?->toIso8601String(),
      'updated_at' => $this->updated_at?->toIso8601String(),
    ];
  }
}
