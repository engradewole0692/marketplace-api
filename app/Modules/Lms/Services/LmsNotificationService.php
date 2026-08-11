<?php

declare(strict_types=1);

namespace App\Modules\Lms\Services;

use App\Contracts\ServiceContract;
use App\Mail\MemberNotificationMail;
use App\Models\User;
use App\Modules\Communications\Services\CommunicationDispatchService;
use App\Modules\Communications\Services\CommunicationLmsBridge;
use App\Modules\Lms\Models\Assignment;
use App\Modules\Lms\Models\AssignmentSubmission;
use App\Modules\Lms\Models\Course;
use App\Modules\Lms\Models\Enrollment;
use App\Modules\Lms\Models\LearningActivity;
use App\Services\Membership\MemberNotificationQueueService;
use Illuminate\Support\Facades\Mail;

/**
 * LMS workflow notifications: email + in-app (member queue) + learning activity audit trail.
 */
final class LmsNotificationService implements ServiceContract
{
  public function __construct(
    private readonly MemberNotificationQueueService $memberQueue,
    private readonly CommunicationDispatchService $communicationDispatch,
    private readonly CommunicationLmsBridge $lmsBridge,
  ) {}

  public function notifyEnrollment(Enrollment $enrollment): void
  {
    $enrollment->loadMissing(['course', 'user.member']);
    $course = $enrollment->course;
    $user = $enrollment->user;
    if ($course === null || $user === null) {
      return;
    }

    $this->writeActivity($user->id, $course->id, $enrollment->id, 'enrollment.created', [
      'title' => 'Enrolled in '.$course->title,
      'body' => 'Your enrollment is '.$enrollment->status?->value,
    ]);

    $this->lmsBridge->notifyEnrollment($enrollment);
  }

  public function notifyCourseCompletion(Enrollment $enrollment): void
  {
    $enrollment->loadMissing(['course', 'user.member']);
    $course = $enrollment->course;
    $user = $enrollment->user;
    if ($course === null || $user === null) {
      return;
    }

    $this->writeActivity($user->id, $course->id, $enrollment->id, 'course.completed', [
      'title' => 'Completed '.$course->title,
      'body' => 'Congratulations on completing this course.',
    ]);

    $this->lmsBridge->notifyCourseCompleted($enrollment);
  }

  public function notifyAssignmentSubmitted(Assignment $assignment, AssignmentSubmission $submission, User $user): void
  {
    $assignment->loadMissing('course');
    $this->writeActivity($user->id, $assignment->course_id, $submission->enrollment_id, 'assignment.submitted', [
      'title' => 'Assignment submitted: '.$assignment->title,
      'body' => 'Attempt #'.$submission->attempt_number,
    ]);

    $this->dispatchLearnerNotification($user, 'lms.assignment.submitted', 'learning', [
      'member_name' => $user->display_name ?: $user->name ?: 'Learner',
      'assignment_title' => $assignment->title,
      'course_name' => $assignment->course?->title,
      'course_title' => $assignment->course?->title,
      'submission_id' => $submission->uuid,
      'in_app_title' => 'Assignment submitted: '.$assignment->title,
      'in_app_body' => 'Attempt #'.$submission->attempt_number,
    ], $submission);
  }

  public function notifyAssignmentGraded(Assignment $assignment, AssignmentSubmission $submission, User $grader): void
  {
    $submission->loadMissing('user.member');
    $user = $submission->user;
    if ($user === null) {
      return;
    }

    $this->writeActivity($user->id, $assignment->course_id, $submission->enrollment_id, 'assignment.graded', [
      'title' => 'Assignment graded: '.$assignment->title,
      'body' => 'Status: '.($submission->status?->value ?? 'graded'),
      'score' => $submission->score,
    ]);

    $this->lmsBridge->notifyAssignmentGraded($submission);
  }

  public function notifyCoursePublished(Course $course, ?User $actor = null): void
  {
    $this->auditOnly($course, $actor, 'course.published.notify', 'Course published notification recorded.');
  }

  /**
   * @param  array<string, mixed>  $payload
   */
  private function dispatchLearnerNotification(
    User $user,
    string $eventKey,
    string $section,
    array $payload,
    ?\Illuminate\Database\Eloquent\Model $related = null,
  ): void {
    try {
      $this->communicationDispatch->dispatchEvent(
        eventKey: $eventKey,
        section: $section,
        variables: $payload,
        recipientUser: $user,
        recipientEmail: $user->email,
        recipientName: $user->display_name ?: $user->name ?: 'Learner',
        related: $related,
        includeRouting: in_array($eventKey, ['lms.assignment.submitted'], true),
      );
    } catch (\Throwable $exception) {
      report($exception);
      $this->notifyUserLegacy($user, $eventKey, $payload, (string) ($payload['in_app_title'] ?? $eventKey));
    }
  }

  /**
   * @param  array<string, mixed>  $payload
   */
  private function notifyUserLegacy(User $user, string $template, array $payload, string $inAppTitle): void
  {
    $member = $user->member;
    if ($member !== null) {
      $this->memberQueue->queue($member, 'email', $template, array_merge($payload, [
        'email' => $user->email,
      ]));
      $this->memberQueue->queue($member, 'in_app', $template, array_merge($payload, [
        'title' => $inAppTitle,
        'body' => (string) ($payload['teacher_comments'] ?? $payload['course_title'] ?? $inAppTitle),
      ]));

      return;
    }

    // Visitor / public learner — direct email (no member queue).
    try {
      Mail::to($user->email)->queue(new MemberNotificationMail(
        $template,
        array_merge($payload, ['email' => $user->email]),
        $user->display_name ?: $user->name ?: 'Learner',
      ));
    } catch (\Throwable $exception) {
      report($exception);
    }
  }

  /**
   * @param  array<string, mixed>  $meta
   */
  private function writeActivity(
    int $userId,
    ?int $courseId,
    ?int $enrollmentId,
    string $type,
    array $meta,
  ): void {
    if (! class_exists(LearningActivity::class)) {
      return;
    }

    try {
      LearningActivity::query()->create([
        'user_id' => $userId,
        'course_id' => $courseId,
        'enrollment_id' => $enrollmentId,
        'event_type' => $type,
        'title' => (string) ($meta['title'] ?? $type),
        'description' => (string) ($meta['body'] ?? ''),
        'metadata' => $meta,
        'occurred_at' => now(),
      ]);
    } catch (\Throwable $exception) {
      report($exception);
    }
  }

  private function auditOnly(Course $course, ?User $actor, string $event, string $description): void
  {
    try {
      app(LmsAuditService::class)->record($course, $actor, $event, $description);
    } catch (\Throwable $exception) {
      report($exception);
    }
  }
}
