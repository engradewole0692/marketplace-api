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
            'user_id' => $this->user_id,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'full_name' => $this->full_name,
            'email' => $this->email,
            'phone' => $this->phone,
            'business_name' => $this->business_name,
            'business_location' => $this->business_location,
            'country' => $this->country,
            'state_province' => $this->state_province,
            'business_industry' => $this->business_industry,
            'business_description' => $this->business_description,
            'business_stage' => $this->business_stage,
            'years_in_operation' => $this->years_in_operation,
            'employee_count' => $this->employee_count,
            'advice_areas' => $this->advice_areas,
            'main_challenges' => $this->main_challenges,
            'business_goals' => $this->business_goals,
            'website_url' => $this->website_url,
            'social_links' => $this->social_links,
            'website_social' => $this->website_social,
            'referral_source' => $this->referral_source,
            'preferred_contact' => $this->preferred_contact,
            'additional_info' => $this->additional_info,
            'extra_answers' => $this->extra_answers,
            'status' => $this->status,
            'admin_notes' => $this->when(
                $request->user()?->can('view', $this->resource),
                $this->admin_notes,
            ),
            'assigned_to' => $this->whenLoaded('assignedTo', fn () => [
                'id' => $this->assignedTo?->id,
                'uuid' => $this->assignedTo?->uuid,
                'name' => $this->assignedTo?->name,
                'email' => $this->assignedTo?->email,
            ]),
            'notes' => $this->whenLoaded('notes', fn () => $this->notes->map(fn ($note) => [
                'id' => $note->uuid,
                'content' => $note->content,
                'author' => $note->author?->name,
                'created_at' => $note->created_at->toISOString(),
            ])),
            'history' => $this->whenLoaded('statusHistories', fn () => $this->statusHistories->map(fn ($row) => [
                'id' => $row->uuid,
                'from_status' => $row->from_status,
                'to_status' => $row->to_status,
                'note' => $row->note,
                'actor' => $row->actor?->name,
                'created_at' => $row->created_at->toISOString(),
            ])),
            'conversation_id' => $this->conversation?->uuid,
            'created_at' => $this->created_at->toISOString(),
            'updated_at' => $this->updated_at->toISOString(),
        ];
    }
}
