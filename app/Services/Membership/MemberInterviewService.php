<?php

declare(strict_types=1);

namespace App\Services\Membership;

use App\Contracts\ServiceContract;
use App\Enums\MemberAuditEventType;
use App\Enums\MemberInterviewStatus;
use App\Enums\MemberStatus;
use App\Enums\MemberTimelineEventType;
use App\Models\Member;
use App\Models\MemberInterview;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class MemberInterviewService implements ServiceContract
{
  public function __construct(
    private readonly MemberAuditService $auditService,
    private readonly MemberManagementService $memberManagementService,
    private readonly MemberNotificationQueueService $notificationQueueService,
    private readonly MemberOnboardingService $onboardingService,
    private readonly MemberPostInterviewAutomationService $postInterviewAutomationService,
  ) {}

  public function paginate(array $filters = []): LengthAwarePaginator
  {
    $query = MemberInterview::query()
      ->with(['member', 'interviewer', 'interviewers'])
      ->latest();

    if (! empty($filters['status'])) {
      $query->where('status', $filters['status']);
    }

    if (! empty($filters['member_id'])) {
      $query->where('member_id', $filters['member_id']);
    }

    if (! empty($filters['interviewer_id'])) {
      $query->where(function ($q) use ($filters): void {
        $q->where('interviewer_id', $filters['interviewer_id'])
          ->orWhereHas('interviewers', fn ($iq) => $iq->where('users.id', $filters['interviewer_id']));
      });
    }

    if (! empty($filters['date'])) {
      $query->whereDate('scheduled_date', $filters['date']);
    }

    if (! empty($filters['scheduled_month'])) {
      $month = (string) $filters['scheduled_month'];
      if (preg_match('/^\d{4}-\d{2}$/', $month) === 1) {
        $query->whereYear('scheduled_date', (int) substr($month, 0, 4))
          ->whereMonth('scheduled_date', (int) substr($month, 5, 2));
      }
    }

    if (! empty($filters['search'])) {
      $search = (string) $filters['search'];
      $query->whereHas('member', function ($q) use ($search): void {
        $q->where('first_name', 'like', "%{$search}%")
          ->orWhere('last_name', 'like', "%{$search}%")
          ->orWhere('email', 'like', "%{$search}%");
      });
    }

    $perPage = min(max((int) ($filters['per_page'] ?? 25), 1), 100);

    return $query->paginate($perPage);
  }

  /**
   * @param  array<string, mixed>  $data
   */
  public function schedule(Member $member, array $data, User $actor): MemberInterview
  {
    return DB::transaction(function () use ($member, $data, $actor): MemberInterview {
      $token = Str::random(48);
      $frontend = rtrim((string) config('app-frontend.url', config('app.url')), '/');

      $interview = MemberInterview::query()->create([
        'member_id' => $member->id,
        'parent_interview_id' => $data['parent_interview_id'] ?? null,
        'status' => MemberInterviewStatus::InvitationSent,
        'interview_type' => $data['interview_type'] ?? 'online',
        'scheduled_date' => $data['scheduled_date'] ?? null,
        'scheduled_time' => $data['scheduled_time'] ?? null,
        'duration_minutes' => $data['duration_minutes'] ?? 60,
        'timezone' => $data['timezone'] ?? config('app.timezone', 'UTC'),
        'interviewer_id' => $data['interviewer_id'] ?? null,
        'external_interviewer_name' => $data['external_interviewer_name'] ?? null,
        'meeting_link' => $data['meeting_link'] ?? null,
        'meeting_platform' => $data['meeting_platform'] ?? null,
        'meeting_password' => $data['meeting_password'] ?? null,
        'physical_location' => $data['physical_location'] ?? null,
        'venue' => $data['venue'] ?? null,
        'remarks' => $data['remarks'] ?? null,
        'instructions' => $data['instructions'] ?? null,
        'confirmation_token' => $token,
        'invitation_sent_at' => now(),
        'created_by' => $actor->id,
        'updated_by' => $actor->id,
      ]);

      $interviewerIds = $this->resolveInterviewerIds($data);
      if ($interviewerIds !== []) {
        $sync = [];
        foreach ($interviewerIds as $index => $userId) {
          $sync[$userId] = ['is_primary' => $index === 0];
        }
        $interview->interviewers()->sync($sync);
        if ($interview->interviewer_id === null) {
          $interview->interviewer_id = $interviewerIds[0];
          $interview->save();
        }
      }

      $current = $this->memberStatus($member);

      if ($current->canTransitionTo(MemberStatus::InterviewScheduled)) {
        $this->memberManagementService->transitionStatus(
          $member,
          MemberStatus::InterviewScheduled,
          $actor,
          'Interview scheduled.',
        );
      }

      $member = $member->fresh() ?? $member;
      $current = $this->memberStatus($member);
      if ($current->canTransitionTo(MemberStatus::InterviewInvitationSent)) {
        $this->memberManagementService->transitionStatus(
          $member,
          MemberStatus::InterviewInvitationSent,
          $actor,
          'Interview invitation sent to applicant.',
        );
      }

      $confirmUrl = $frontend.'/membership/interview/confirm?token='.$token;

      $payload = [
        'interview_uuid' => $interview->uuid,
        'interview_type' => $interview->interview_type,
        'scheduled_date' => $interview->scheduled_date?->toDateString(),
        'scheduled_time' => $interview->scheduled_time,
        'timezone' => $interview->timezone,
        'duration_minutes' => $interview->duration_minutes,
        'meeting_link' => $interview->meeting_link,
        'meeting_platform' => $interview->meeting_platform,
        'meeting_password' => $interview->meeting_password,
        'physical_location' => $interview->physical_location,
        'venue' => $interview->venue,
        'instructions' => $interview->instructions ?? $interview->remarks,
        'confirmation_url' => $confirmUrl,
        'ical_url' => $frontend.'/api/v1/public/membership/interviews/'.$interview->uuid.'/ics?token='.$token,
      ];

      $this->notificationQueueService->queueMany($member, [
        ['channel' => 'email', 'template' => 'interview_invitation', 'payload' => $payload],
        ['channel' => 'email', 'template' => 'interview_scheduled', 'payload' => $payload],
        ['channel' => 'in_app', 'template' => 'interview_invitation', 'payload' => $payload],
      ]);

      // Notify assigned interviewers (in-app).
      foreach ($interviewerIds as $interviewerId) {
        $this->notificationQueueService->queueMany($member, [
          [
            'channel' => 'in_app',
            'template' => 'interview_assigned_interviewer',
            'payload' => array_merge($payload, ['interviewer_user_id' => $interviewerId]),
          ],
        ]);
      }

      $this->auditService->recordWithTimeline(
        MemberAuditEventType::InterviewInvitationSent,
        MemberTimelineEventType::InterviewInvitationSent,
        $member,
        'Membership interview scheduled and invitation sent.',
        $actor,
        null,
        ['interview_uuid' => $interview->uuid],
      );

      $this->auditService->recordWithTimeline(
        MemberAuditEventType::InterviewScheduled,
        MemberTimelineEventType::InterviewScheduled,
        $member,
        'Membership interview scheduled.',
        $actor,
        null,
        ['interview_uuid' => $interview->uuid],
      );

      return $interview->fresh(['member', 'interviewer', 'interviewers']);
    });
  }

  public function confirmByToken(string $token): MemberInterview
  {
    $interview = MemberInterview::query()
      ->where('confirmation_token', $token)
      ->with('member')
      ->firstOrFail();

    return DB::transaction(function () use ($interview): MemberInterview {
      $interview->status = MemberInterviewStatus::Confirmed;
      $interview->confirmed_at = now();
      $interview->save();

      $member = $interview->member;
      if ($member !== null) {
        $actor = User::query()->find($interview->created_by)
          ?? User::query()->find($interview->updated_by)
          ?? User::query()->whereHas('roles', fn ($q) => $q->where('slug', 'super-admin'))->first()
          ?? User::query()->first();

        if ($actor !== null) {
          $current = $this->memberStatus($member);
          if ($current->canTransitionTo(MemberStatus::InterviewConfirmed)) {
            $this->memberManagementService->transitionStatus(
              $member,
              MemberStatus::InterviewConfirmed,
              $actor,
              'Applicant confirmed interview attendance.',
            );
          }

          $this->auditService->recordWithTimeline(
            MemberAuditEventType::InterviewConfirmed,
            MemberTimelineEventType::InterviewConfirmed,
            $member,
            'Applicant confirmed interview invitation.',
            $actor,
            null,
            ['interview_uuid' => $interview->uuid],
          );
        }

        $this->notificationQueueService->queueMany($member, [
          [
            'channel' => 'email',
            'template' => 'interview_confirmed',
            'payload' => ['interview_uuid' => $interview->uuid],
          ],
          [
            'channel' => 'in_app',
            'template' => 'interview_confirmed',
            'payload' => ['interview_uuid' => $interview->uuid],
          ],
        ]);
      }

      return $interview->fresh(['member', 'interviewer', 'interviewers']);
    });
  }

  /**
   * @param  array<string, mixed>  $data
   */
  public function update(MemberInterview $interview, array $data, User $actor): MemberInterview
  {
    return DB::transaction(function () use ($interview, $data, $actor): MemberInterview {
      $previousStatus = $interview->status instanceof MemberInterviewStatus
        ? $interview->status
        : MemberInterviewStatus::from((string) $interview->status);
      $previousDate = $interview->scheduled_date?->toDateString();
      $previousTime = $interview->scheduled_time;

      $interview->fill([
        'status' => $data['status'] ?? $interview->status,
        'interview_type' => $data['interview_type'] ?? $interview->interview_type,
        'scheduled_date' => $data['scheduled_date'] ?? $interview->scheduled_date,
        'scheduled_time' => $data['scheduled_time'] ?? $interview->scheduled_time,
        'duration_minutes' => array_key_exists('duration_minutes', $data)
          ? $data['duration_minutes']
          : $interview->duration_minutes,
        'timezone' => array_key_exists('timezone', $data) ? $data['timezone'] : $interview->timezone,
        'interviewer_id' => array_key_exists('interviewer_id', $data)
          ? $data['interviewer_id']
          : $interview->interviewer_id,
        'external_interviewer_name' => array_key_exists('external_interviewer_name', $data)
          ? $data['external_interviewer_name']
          : $interview->external_interviewer_name,
        'meeting_link' => array_key_exists('meeting_link', $data)
          ? $data['meeting_link']
          : $interview->meeting_link,
        'meeting_platform' => array_key_exists('meeting_platform', $data)
          ? $data['meeting_platform']
          : $interview->meeting_platform,
        'meeting_password' => array_key_exists('meeting_password', $data)
          ? $data['meeting_password']
          : $interview->meeting_password,
        'physical_location' => array_key_exists('physical_location', $data)
          ? $data['physical_location']
          : $interview->physical_location,
        'venue' => array_key_exists('venue', $data)
          ? $data['venue']
          : $interview->venue,
        'remarks' => array_key_exists('remarks', $data)
          ? $data['remarks']
          : $interview->remarks,
        'instructions' => array_key_exists('instructions', $data)
          ? $data['instructions']
          : $interview->instructions,
        'result' => $data['result'] ?? $interview->result,
        'updated_by' => $actor->id,
      ]);
      $interview->save();

      if (array_key_exists('interviewer_ids', $data) && is_array($data['interviewer_ids'])) {
        $sync = [];
        foreach (array_values($data['interviewer_ids']) as $index => $userId) {
          $sync[(int) $userId] = ['is_primary' => $index === 0];
        }
        $interview->interviewers()->sync($sync);
      }

      $member = $interview->member;
      if ($member === null) {
        return $interview->fresh(['member', 'interviewer', 'interviewers']);
      }

      $status = $interview->status instanceof MemberInterviewStatus
        ? $interview->status
        : MemberInterviewStatus::from((string) $interview->status);

      $rescheduled = ($previousDate !== $interview->scheduled_date?->toDateString()
        || $previousTime !== $interview->scheduled_time)
        && in_array($status, [
          MemberInterviewStatus::Scheduled,
          MemberInterviewStatus::Pending,
          MemberInterviewStatus::InvitationSent,
          MemberInterviewStatus::Confirmed,
          MemberInterviewStatus::Rescheduled,
        ], true);

      if ($rescheduled || $status === MemberInterviewStatus::Rescheduled) {
        $this->handleReschedule($interview, $member, $actor, $previousStatus);
      }

      if ($status === MemberInterviewStatus::Completed) {
        $current = $this->memberStatus($member);
        if ($current->canTransitionTo(MemberStatus::InterviewCompleted)) {
          $this->memberManagementService->transitionStatus(
            $member,
            MemberStatus::InterviewCompleted,
            $actor,
            'Interview marked completed.',
          );
        }
      }

      if ($status === MemberInterviewStatus::Passed) {
        $interview->result = MemberInterviewStatus::Passed->value;
        $interview->save();
        $this->onboardingService->autoComplete($member, 'interview_passed', $actor);
        $this->notificationQueueService->queue(
          $member,
          'email',
          'interview_passed',
          ['interview_uuid' => $interview->uuid],
        );
        $this->auditService->recordWithTimeline(
          MemberAuditEventType::InterviewPassed,
          MemberTimelineEventType::InterviewPassed,
          $member,
          'Interview passed — starting automatic onboarding.',
          $actor,
          null,
          ['interview_uuid' => $interview->uuid],
        );
        $this->postInterviewAutomationService->runAfterInterviewPassed($member->fresh() ?? $member, $actor);

        return $interview->fresh(['member', 'interviewer', 'interviewers']);
      }

      if ($status === MemberInterviewStatus::Failed) {
        $interview->result = MemberInterviewStatus::Failed->value;
        $interview->save();
        $current = $this->memberStatus($member->fresh() ?? $member);
        if ($current->canTransitionTo(MemberStatus::InterviewFailed)) {
          $this->memberManagementService->transitionStatus(
            $member,
            MemberStatus::InterviewFailed,
            $actor,
            'Interview failed — pending admin decision.',
          );
        } elseif ($current->canTransitionTo(MemberStatus::InterviewCompleted)) {
          $this->memberManagementService->transitionStatus(
            $member,
            MemberStatus::InterviewCompleted,
            $actor,
            'Interview completed with fail result.',
          );
        }
        $this->notificationQueueService->queue(
          $member,
          'email',
          'interview_failed',
          ['interview_uuid' => $interview->uuid],
        );
        $this->auditService->recordWithTimeline(
          MemberAuditEventType::InterviewFailed,
          MemberTimelineEventType::InterviewFailed,
          $member,
          'Interview failed.',
          $actor,
          null,
          ['interview_uuid' => $interview->uuid],
        );
      }

      if ($status === MemberInterviewStatus::Cancelled && $previousStatus !== MemberInterviewStatus::Cancelled) {
        $current = $this->memberStatus($member->fresh() ?? $member);
        if ($current === MemberStatus::InterviewScheduled
          || $current === MemberStatus::InterviewInvitationSent
          || $current === MemberStatus::InterviewConfirmed) {
          if ($current->canTransitionTo(MemberStatus::InterviewRequired)) {
            $this->memberManagementService->transitionStatus(
              $member,
              MemberStatus::InterviewRequired,
              $actor,
              'Interview cancelled.',
            );
          }
        }
        $this->notificationQueueService->queue(
          $member,
          'email',
          'interview_cancelled',
          ['interview_uuid' => $interview->uuid],
        );
      }

      $this->auditService->recordWithTimeline(
        MemberAuditEventType::InterviewUpdated,
        MemberTimelineEventType::InterviewCompleted,
        $member,
        match ($status) {
          MemberInterviewStatus::Failed => 'Interview failed.',
          MemberInterviewStatus::Cancelled => 'Interview cancelled.',
          MemberInterviewStatus::Completed => 'Interview completed.',
          MemberInterviewStatus::Rescheduled => 'Interview rescheduled.',
          default => $rescheduled ? 'Interview rescheduled.' : 'Interview record updated.',
        },
        $actor,
        null,
        [
          'interview_uuid' => $interview->uuid,
          'status' => $status->value,
          'rescheduled' => $rescheduled,
        ],
      );

      return $interview->fresh(['member', 'interviewer', 'interviewers']);
    });
  }

  private function handleReschedule(
    MemberInterview $interview,
    Member $member,
    User $actor,
    MemberInterviewStatus $previousStatus,
  ): void {
    if ($interview->confirmation_token === null) {
      $interview->confirmation_token = Str::random(48);
    }
    $interview->status = MemberInterviewStatus::InvitationSent;
    $interview->invitation_sent_at = now();
    $interview->confirmed_at = null;
    $interview->awaiting_review_notified_at = null;
    $interview->save();

    $current = $this->memberStatus($member);
    if ($current->canTransitionTo(MemberStatus::InterviewRescheduled)) {
      $this->memberManagementService->transitionStatus(
        $member,
        MemberStatus::InterviewRescheduled,
        $actor,
        'Interview rescheduled.',
      );
    }
    $member = $member->fresh() ?? $member;
    $current = $this->memberStatus($member);
    if ($current->canTransitionTo(MemberStatus::InterviewInvitationSent)) {
      $this->memberManagementService->transitionStatus(
        $member,
        MemberStatus::InterviewInvitationSent,
        $actor,
        'Reschedule invitation sent.',
      );
    }

    $frontend = rtrim((string) config('app-frontend.url', config('app.url')), '/');
    $this->notificationQueueService->queueMany($member, [
      [
        'channel' => 'email',
        'template' => 'interview_rescheduled',
        'payload' => [
          'interview_uuid' => $interview->uuid,
          'scheduled_date' => $interview->scheduled_date?->toDateString(),
          'scheduled_time' => $interview->scheduled_time,
          'confirmation_url' => $frontend.'/membership/interview/confirm?token='.$interview->confirmation_token,
          'previous_status' => $previousStatus->value,
        ],
      ],
    ]);

    $this->auditService->recordWithTimeline(
      MemberAuditEventType::InterviewRescheduled,
      MemberTimelineEventType::InterviewRescheduled,
      $member,
      'Interview rescheduled and new invitation sent.',
      $actor,
      null,
      ['interview_uuid' => $interview->uuid],
    );
  }

  /**
   * @param  array<string, mixed>  $data
   * @return list<int>
   */
  private function resolveInterviewerIds(array $data): array
  {
    $ids = [];
    if (! empty($data['interviewer_ids']) && is_array($data['interviewer_ids'])) {
      foreach ($data['interviewer_ids'] as $id) {
        $ids[] = (int) $id;
      }
    }
    if (! empty($data['interviewer_id'])) {
      $ids[] = (int) $data['interviewer_id'];
    }

    return array_values(array_unique(array_filter($ids)));
  }

  private function memberStatus(Member $member): MemberStatus
  {
    return $member->status instanceof MemberStatus
      ? $member->status
      : MemberStatus::from((string) $member->status);
  }
}
