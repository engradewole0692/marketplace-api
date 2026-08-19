<?php

declare(strict_types=1);

namespace App\Modules\BusinessReview\Http\Resources;

use App\Modules\BusinessReview\Models\BusinessReview;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin BusinessReview */
final class BusinessReviewResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'full_name' => $this->full_name,
            'email' => $this->email,
            'phone' => $this->phone,
            'business_name' => $this->business_name,
            'business_location' => $this->business_location,
            'business_industry' => $this->business_industry,
            'business_description' => $this->business_description,
            'business_stage' => $this->business_stage,
            'main_challenges' => $this->main_challenges,
            'business_goals' => $this->business_goals,
            'website_social' => $this->website_social,
            'preferred_contact' => $this->preferred_contact,
            'additional_info' => $this->additional_info,
            'extra_answers' => $this->extra_answers,
            'status' => $this->status,
            'admin_notes' => $this->admin_notes,
            'assigned_to' => $this->whenLoaded('assignedTo', fn () => [
                'id' => $this->assignedTo?->uuid ?? $this->assignedTo?->id,
                'name' => $this->assignedTo?->name,
                'email' => $this->assignedTo?->email,
            ]),
            'notes' => $this->whenLoaded('notes', fn () => $this->notes->map(fn ($note) => [
                'id' => $note->uuid,
                'content' => $note->content,
                'author' => $note->author?->name,
                'created_at' => $note->created_at->toISOString(),
            ])),
            'conversation_id' => $this->conversation?->uuid,
            'created_at' => $this->created_at->toISOString(),
            'updated_at' => $this->updated_at->toISOString(),
        ];
    }
}
