<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\MemberAuditEventType;
use App\Enums\MemberInterviewStatus;
use App\Enums\MemberTimelineEventType;
use App\Models\MemberInterview;
use App\Models\User;
use App\Services\Membership\MemberAuditService;
use App\Services\Membership\MemberNotificationQueueService;
use Illuminate\Console\Command;

final class NotifyAwaitingInterviewReviewCommand extends Command
{
  protected $signature = 'membership:notify-awaiting-interview-review';

  protected $description = 'Notify membership admins when interview time has elapsed and outcome is still pending.';

  public function handle(
    MemberNotificationQueueService $notificationQueue,
    MemberAuditService $auditService,
  ): int {
    $now = now();

    $interviews = MemberInterview::query()
      ->with('member')
      ->whereNull('awaiting_review_notified_at')
      ->whereNotNull('scheduled_date')
      ->whereIn('status', [
        MemberInterviewStatus::Scheduled->value,
        MemberInterviewStatus::InvitationSent->value,
        MemberInterviewStatus::Confirmed->value,
        MemberInterviewStatus::Completed->value,
        MemberInterviewStatus::AwaitingReview->value,
      ])
      ->whereDate('scheduled_date', '<=', $now->toDateString())
      ->limit(100)
      ->get();

    $notified = 0;

    foreach ($interviews as $interview) {
      $start = $interview->scheduled_date?->copy();
      if ($start === null) {
        continue;
      }
      if ($interview->scheduled_time) {
        [$h, $m] = array_pad(explode(':', substr((string) $interview->scheduled_time, 0, 5)), 2, '0');
        $start->setTime((int) $h, (int) $m);
      } else {
        $start->setTime(23, 59);
      }

      $end = $start->copy()->addMinutes((int) ($interview->duration_minutes ?? 60));
      if ($end->isFuture()) {
        continue;
      }

      $member = $interview->member;
      if ($member === null) {
        continue;
      }

      $interview->status = MemberInterviewStatus::AwaitingReview;
      $interview->awaiting_review_notified_at = now();
      $interview->save();

      $notificationQueue->queueMany($member, [
        [
          'channel' => 'email',
          'template' => 'interview_awaiting_review',
          'payload' => [
            'interview_uuid' => $interview->uuid,
            'message' => 'This interview is awaiting review.',
            'scheduled_date' => $interview->scheduled_date?->toDateString(),
            'scheduled_time' => $interview->scheduled_time,
          ],
        ],
        [
          'channel' => 'in_app',
          'template' => 'interview_awaiting_review',
          'payload' => [
            'interview_uuid' => $interview->uuid,
            'message' => 'This interview is awaiting review.',
          ],
        ],
      ]);

      $actor = User::query()->find($interview->created_by) ?? User::query()->first();
      if ($actor !== null) {
        $auditService->recordWithTimeline(
          MemberAuditEventType::AwaitingInterviewReview,
          MemberTimelineEventType::InterviewCompleted,
          $member,
          'Interview time elapsed — awaiting membership review.',
          $actor,
          null,
          ['interview_uuid' => $interview->uuid],
        );
      }

      $notified++;
    }

    $this->info("Notified {$notified} interview(s) awaiting review.");

    return self::SUCCESS;
  }
}
