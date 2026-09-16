<?php

declare(strict_types=1);

namespace App\Modules\Events\Http\Resources;

use App\Models\Person;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Person */
final class PersonResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'person_no' => $this->person_no,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'name' => $this->fullName(),
            'email' => $this->email,
            'phone' => $this->phone,
            'phone_country_code' => $this->phone_country_code,
            'country' => $this->whenLoaded('country', fn () => $this->country ? [
                'id' => $this->country->uuid,
                'name' => $this->country->name,
                'slug' => $this->country->slug,
                'code' => $this->country->code,
            ] : null),
            'region' => $this->region,
            'city' => $this->city,
            'organization' => $this->organization,
            'is_member' => $this->relationLoaded('member') ? $this->member !== null : $this->member()->exists(),
            'member_id' => $this->whenLoaded('member', fn () => $this->member?->uuid),
            'has_user' => $this->user_id !== null,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
