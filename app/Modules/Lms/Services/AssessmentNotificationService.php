<?php

declare(strict_types=1);

namespace App\Modules\Lms\Services;

use App\Contracts\ServiceContract;
use App\Modules\Lms\Models\AssessmentAttempt;
use App\Services\Membership\MemberNotificationQueueService;

final class AssessmentNotificationService implements ServiceContract
{
  public function __construct(
    private readonly MemberNotificationQueueService $queue,
  ) {}

  public function notifyResult(AssessmentAttempt $attempt): void
  {
    $attempt->loadMissing(['user.member', 'assessment']);
    $member = $attempt->user?->member;
    if (! $member) {
      // Public learners without a member record still get in-app learning activity;
      // email queue requires MemberNotificationQueue membership linkage.
      return;
    }

    $this->queue->queue($member, 'email', 'lms.assessment.result', [
      'assessment_title' => $attempt->assessment?->title,
      'percentage' => (float) $attempt->percentage,
      'grade' => $attempt->grade,
      'passed' => (bool) $attempt->passed,
      'remarks' => $attempt->remarks,
      'attempt_id' => $attempt->uuid,
      'score' => (float) $attempt->score,
      'max_score' => (float) $attempt->max_score,
    ]);

    $this->queue->queue($member, 'in_app', 'lms.assessment.result', [
      'title' => 'Assessment result: '.($attempt->assessment?->title ?? 'Assessment'),
      'body' => $attempt->remarks,
      'attempt_id' => $attempt->uuid,
      'percentage' => (float) $attempt->percentage,
      'grade' => $attempt->grade,
    ]);
  }
}
