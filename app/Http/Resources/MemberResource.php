<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Member;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Member
 */
final class MemberResource extends JsonResource
{
  /**
   * @return array<string, mixed>
   */
  public function toArray(Request $request): array
  {
    return [
      'id' => $this->id,
      'uuid' => $this->uuid,
      'membership_number' => $this->membership_number,
      'application_number' => $this->application_number ?? $this->membership_number,
      'photo_path' => $this->photo_path,
      'photo_media_id' => $this->whenLoaded('photoMedia', fn () => $this->photoMedia?->uuid),
      'photo_url' => $this->photoUrl(),
      'title' => $this->title,
      'first_name' => $this->first_name,
      'middle_name' => $this->middle_name,
      'last_name' => $this->last_name,
      'display_name' => $this->display_name,
      'name' => $this->fullName(),
      'gender' => $this->gender,
      'date_of_birth' => $this->date_of_birth?->toDateString(),
      'phone' => $this->phone,
      'alternate_phone' => $this->alternate_phone,
      'email' => $this->email,
      'occupation' => $this->occupation,
      'organization' => $this->organization,
      'marketplace_sector' => $this->marketplace_sector,
      'skills' => $this->skills,
      'languages' => $this->languages,
      'biography' => $this->biography,
      'country_id' => $this->country_id,
      'city' => $this->city,
      'state' => $this->state,
      'region_id' => $this->region_id,
      'ministry_id' => $this->ministry_id,
      'preferred_ministry_id' => $this->preferred_ministry_id,
      'profession' => $this->profession,
      'church_name' => $this->church_name,
      'church_address' => $this->church_address,
      'years_of_experience' => $this->years_of_experience,
      'years_in_faith' => $this->years_in_faith,
      'ministry_interests' => $this->ministry_interests,
      'gifts' => $this->gifts,
      'references' => $this->references,
      'education' => $this->education,
      'availability' => $this->availability,
      'interview_notes' => $this->interview_notes,
      'onboarding_notes' => $this->onboarding_notes,
      'user_id' => $this->user_id,
      'activated_at' => $this->activated_at?->toIso8601String(),
      'orientation_completed_at' => $this->orientation_completed_at?->toIso8601String(),
      'ministry' => $this->whenLoaded('ministry', fn () => [
        'id' => $this->ministry?->uuid,
        'name' => $this->ministry?->name,
        'slug' => $this->ministry?->slug,
      ]),
      'preferred_ministry' => $this->whenLoaded('preferredMinistry', fn () => [
        'id' => $this->preferredMinistry?->uuid,
        'name' => $this->preferredMinistry?->name,
        'slug' => $this->preferredMinistry?->slug,
      ]),
      'country' => $this->whenLoaded('country', fn () => [
        'id' => $this->country?->uuid ?? $this->country?->id,
        'name' => $this->country?->name,
        'slug' => $this->country?->slug,
        'code' => $this->country?->code,
      ]),
      'interviews' => MemberInterviewResource::collection($this->whenLoaded('interviews')),
      'status' => $this->status instanceof \BackedEnum ? $this->status->value : $this->status,
      'status_label' => $this->status instanceof \App\Enums\MemberStatus
        ? $this->status->label()
        : (string) $this->status,
      'approval_status' => $this->approval_status instanceof \BackedEnum ? $this->approval_status->value : $this->approval_status,
      'joined_at' => $this->joined_at?->toDateString(),
      'tags' => MemberTagResource::collection($this->whenLoaded('tags')),
      'contacts' => MemberContactResource::collection($this->whenLoaded('contacts')),
      'addresses' => MemberAddressResource::collection($this->whenLoaded('addresses')),
      'created_by' => $this->created_by,
      'updated_by' => $this->updated_by,
      'creator' => new UserResource($this->whenLoaded('creator')),
      'deleted_at' => $this->deleted_at?->toIso8601String(),
      'created_at' => $this->created_at?->toIso8601String(),
      'updated_at' => $this->updated_at?->toIso8601String(),
    ];
  }
}
