<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Public;

use App\Http\Controllers\Api\V1\ApiController;
use App\Models\Member;
use App\Models\MemberInterview;
use App\Services\Membership\MemberInterviewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class MembershipApplicationPublicController extends ApiController
{
  public function status(Request $request): JsonResponse
  {
    $validated = $request->validate([
      'token' => ['required', 'string', 'min:20', 'max:120'],
    ]);

    $member = Member::query()
      ->where('application_tracking_token', $validated['token'])
      ->with([
        'timelines' => fn ($q) => $q->latest('occurred_at')->limit(40),
        'interviews' => fn ($q) => $q->latest()->limit(5),
        'preferredMinistry',
        'country',
      ])
      ->firstOrFail();

    $latestInterview = $member->interviews->first();

    return $this->responder->success(
      data: [
        'application_number' => $member->application_number ?? $member->membership_number,
        'membership_number' => $member->membership_number,
        'status' => $member->status instanceof \BackedEnum ? $member->status->value : $member->status,
        'status_label' => $member->status instanceof \App\Enums\MemberStatus
          ? $member->status->label()
          : (string) $member->status,
        'applicant' => [
          'first_name' => $member->first_name,
          'last_name' => $member->last_name,
          'email' => $member->email,
        ],
        'preferred_ministry' => $member->preferredMinistry?->name,
        'country' => $member->country?->name,
        'next_step' => $this->nextStep($member),
        'interview' => $latestInterview ? [
          'id' => $latestInterview->uuid,
          'status' => $latestInterview->status instanceof \BackedEnum
            ? $latestInterview->status->value
            : $latestInterview->status,
          'interview_type' => $latestInterview->interview_type,
          'scheduled_date' => $latestInterview->scheduled_date?->toDateString(),
          'scheduled_time' => $latestInterview->scheduled_time,
          'timezone' => $latestInterview->timezone,
          'meeting_link' => $latestInterview->meeting_link,
          'venue' => $latestInterview->venue ?? $latestInterview->physical_location,
          'can_confirm' => $latestInterview->confirmation_token
            && $latestInterview->confirmed_at === null
            && ! in_array(
              $latestInterview->status instanceof \BackedEnum ? $latestInterview->status->value : (string) $latestInterview->status,
              ['passed', 'failed', 'cancelled'],
              true,
            ),
          'confirmation_token' => $latestInterview->confirmed_at === null
            ? $latestInterview->confirmation_token
            : null,
        ] : null,
        'timeline' => $member->timelines->map(fn ($entry) => [
          'id' => $entry->uuid ?? $entry->id,
          'event_type' => $entry->event_type instanceof \BackedEnum ? $entry->event_type->value : $entry->event_type,
          'description' => $entry->description,
          'occurred_at' => $entry->occurred_at?->toIso8601String() ?? $entry->created_at?->toIso8601String(),
        ])->values(),
      ],
      message: 'Application status retrieved.',
    );
  }

  public function confirmInterview(Request $request, MemberInterviewService $service): JsonResponse
  {
    $validated = $request->validate([
      'token' => ['required', 'string', 'min:20', 'max:120'],
    ]);

    $interview = $service->confirmByToken($validated['token']);

    return $this->responder->success(
      data: [
        'interview_id' => $interview->uuid,
        'status' => $interview->status instanceof \BackedEnum ? $interview->status->value : $interview->status,
        'confirmed_at' => $interview->confirmed_at?->toIso8601String(),
      ],
      message: 'Interview attendance confirmed. Thank you.',
    );
  }

  public function interviewIcs(Request $request, string $interviewUuid): Response
  {
    $validated = $request->validate([
      'token' => ['required', 'string', 'min:20', 'max:120'],
    ]);

    $interview = MemberInterview::query()
      ->where('uuid', $interviewUuid)
      ->where('confirmation_token', $validated['token'])
      ->with('member')
      ->firstOrFail();

    $date = $interview->scheduled_date?->format('Ymd') ?? now()->format('Ymd');
    $time = $interview->scheduled_time
      ? str_replace(':', '', substr((string) $interview->scheduled_time, 0, 5)).'00'
      : '090000';
    $duration = (int) ($interview->duration_minutes ?? 60);
    $end = \Carbon\Carbon::createFromFormat('Ymd His', $date.' '.$time)?->addMinutes($duration);
    $endStamp = $end?->format('Ymd\THis') ?? $date.'T'. $time;
    $startStamp = $date.'T'.$time;
    $summary = 'Marketplace Ministers Membership Interview';
    $location = $interview->meeting_link
      ?: ($interview->venue ?: $interview->physical_location ?: 'TBA');
    $description = trim((string) ($interview->instructions ?: $interview->remarks ?: 'Membership interview'));

    $ics = implode("\r\n", [
      'BEGIN:VCALENDAR',
      'VERSION:2.0',
      'PRODID:-//Marketplace Ministers//Membership Interview//EN',
      'CALSCALE:GREGORIAN',
      'METHOD:PUBLISH',
      'BEGIN:VEVENT',
      'UID:'.$interview->uuid.'@marketplace-ministers',
      'DTSTAMP:'.now()->format('Ymd\THis\Z'),
      'DTSTART:'.$startStamp,
      'DTEND:'.$endStamp,
      'SUMMARY:'.$summary,
      'DESCRIPTION:'.str_replace(["\r", "\n"], ['', '\\n'], $description),
      'LOCATION:'.str_replace(["\r", "\n"], ['', ' '], (string) $location),
      'END:VEVENT',
      'END:VCALENDAR',
    ]);

    return response($ics, 200, [
      'Content-Type' => 'text/calendar; charset=utf-8',
      'Content-Disposition' => 'attachment; filename="membership-interview.ics"',
    ]);
  }

  private function nextStep(Member $member): string
  {
    $status = $member->status instanceof \App\Enums\MemberStatus
      ? $member->status
      : \App\Enums\MemberStatus::from((string) $member->status);

    return match ($status) {
      \App\Enums\MemberStatus::ApplicationSubmitted,
      \App\Enums\MemberStatus::PendingReview,
      \App\Enums\MemberStatus::UnderReview => 'Your application is with the membership team for review.',
      \App\Enums\MemberStatus::InterviewRequired => 'An interview will be scheduled soon.',
      \App\Enums\MemberStatus::InterviewScheduled,
      \App\Enums\MemberStatus::InterviewInvitationSent => 'Please confirm your interview invitation.',
      \App\Enums\MemberStatus::InterviewConfirmed => 'Interview confirmed. Please attend at the scheduled time.',
      \App\Enums\MemberStatus::InterviewCompleted,
      \App\Enums\MemberStatus::InterviewPassed => 'Interview recorded. Finalising membership.',
      \App\Enums\MemberStatus::InterviewFailed => 'Interview outcome under review by membership.',
      \App\Enums\MemberStatus::Approved,
      \App\Enums\MemberStatus::Orientation,
      \App\Enums\MemberStatus::MinistryAssigned,
      \App\Enums\MemberStatus::OnboardingCompleted => 'Onboarding in progress — check your email for login details.',
      \App\Enums\MemberStatus::Active => 'Welcome! Sign in to the Member Portal.',
      \App\Enums\MemberStatus::Rejected => 'This application was not approved. Contact support if you have questions.',
      default => 'We will notify you of the next step by email.',
    };
  }
}
