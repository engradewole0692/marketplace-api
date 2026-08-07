<?php



declare(strict_types=1);



namespace App\Http\Resources;



use App\Models\MemberInterview;

use Illuminate\Http\Request;

use Illuminate\Http\Resources\Json\JsonResource;



/** @mixin MemberInterview */

final class MemberInterviewResource extends JsonResource

{

  public function toArray(Request $request): array

  {

    return [

      'id' => $this->uuid,

      'member_id' => $this->member_id,

      'member' => $this->whenLoaded('member', fn () => [

        'id' => $this->member?->id,

        'uuid' => $this->member?->uuid,

        'name' => $this->member?->fullName(),

        'email' => $this->member?->email,

        'status' => $this->member?->status instanceof \BackedEnum ? $this->member->status->value : $this->member?->status,

      ]),

      'status' => $this->status instanceof \BackedEnum ? $this->status->value : $this->status,

      'interview_type' => $this->interview_type,

      'scheduled_date' => $this->scheduled_date?->toDateString(),

      'scheduled_time' => $this->scheduled_time,

      'duration_minutes' => $this->duration_minutes,

      'timezone' => $this->timezone,

      'interviewer_id' => $this->interviewer_id,

      'interviewer_ids' => $this->whenLoaded('interviewers', fn () => $this->interviewers->pluck('id')->values()),

      'interviewers' => $this->whenLoaded('interviewers', fn () => $this->interviewers->map(fn ($user) => [

        'id' => $user->id,

        'name' => $user->display_name ?? $user->name,

        'email' => $user->email,

        'is_primary' => (bool) ($user->pivot->is_primary ?? false),

      ])->values()),

      'external_interviewer_name' => $this->external_interviewer_name,

      'interviewer_name' => $this->whenLoaded('interviewer', fn () => $this->interviewer?->display_name ?? $this->interviewer?->name)

        ?? $this->external_interviewer_name,

      'meeting_link' => $this->meeting_link,

      'meeting_platform' => $this->meeting_platform,

      'meeting_password' => $this->meeting_password,

      'physical_location' => $this->physical_location,

      'venue' => $this->venue,

      'remarks' => $this->remarks,

      'instructions' => $this->instructions,

      'result' => $this->result,

      'invitation_sent_at' => $this->invitation_sent_at?->toIso8601String(),

      'confirmed_at' => $this->confirmed_at?->toIso8601String(),

      'awaiting_review_notified_at' => $this->awaiting_review_notified_at?->toIso8601String(),

      'created_at' => $this->created_at?->toIso8601String(),

      'updated_at' => $this->updated_at?->toIso8601String(),

    ];

  }

}

