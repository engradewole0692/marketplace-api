<?php

declare(strict_types=1);

namespace App\Modules\Lms\Services;

use App\Contracts\ServiceContract;
use App\Mail\MemberNotificationMail;
use App\Models\User;
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

    $this->notifyUser($user, 'lms.enrollment.created', [
      'course_title' => $course->title,
      'enrollment_id' => $enrollment->uuid,
      'status' => $enrollment->status?->value,
    ], 'Enrolled: '.$course->title);
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

    $this->notifyUser($user, 'lms.course.completed', [
      'course_title' => $course->title,
      'enrollment_id' => $enrollment->uuid,
    ], 'Completed: '.$course->title);
  }

  public function notifyAssignmentSubmitted(Assignment $assignment, AssignmentSubmission $submission, User $user): void
  {
    $assignment->loadMissing('course');
    $this->writeActivity($user->id, $assignment->course_id, $submission->enrollment_id, 'assignment.submitted', [
      'title' => 'Assignment submitted: '.$assignment->title,
      'body' => 'Attempt #'.$submission->attempt_number,
    ]);

    $this->notifyUser($user, 'lms.assignment.submitted', [
      'assignment_title' => $assignment->title,
      'course_title' => $assignment->course?->title,
      'submission_id' => $submission->uuid,
    ], 'Assignment submitted: '.$assignment->title);
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

    $this->notifyUser($user, 'lms.assignment.graded', [
      'assignment_title' => $assignment->title,
      'status' => $submission->status?->value,
      'score' => $submission->score,
      'teacher_comments' => $submission->teacher_comments,
      'submission_id' => $submission->uuid,
    ], 'Assignment graded: '.$assignment->title);
  }

  public function notifyCoursePublished(Course $course, ?User $actor = null): void
  {
    $this->auditOnly($course, $actor, 'course.published.notify', 'Course published notification recorded.');
  }

  /**
   * @param  array<string, mixed>  $payload
   */
  private function notifyUser(User $user, string $template, array $payload, string $inAppTitle): void
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
