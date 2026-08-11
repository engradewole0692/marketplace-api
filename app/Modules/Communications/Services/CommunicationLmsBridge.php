<?php

declare(strict_types=1);

namespace App\Modules\Communications\Services;

use App\Contracts\ServiceContract;
use App\Models\User;
use App\Modules\Donations\Models\Donation;
use App\Modules\Lms\Models\AssessmentAttempt;
use App\Modules\Lms\Models\AssignmentSubmission;
use App\Modules\Lms\Models\CourseOrder;
use App\Modules\Lms\Models\CourseRefund;
use App\Modules\Lms\Models\Enrollment;
use App\Modules\Lms\Models\LmsProgramModule;
use App\Modules\Lms\Models\SchoolEnrollment;
use App\Modules\Lms\Models\SchoolOrder;

/** LMS, auth, and learner communication helpers. */
final class CommunicationLmsBridge implements ServiceContract
{
  public function __construct(
    private readonly CommunicationDispatchService $dispatch,
  ) {}

  public function notifyLearnerRegistered(User $user): void
  {
    $frontend = rtrim((string) config('app-frontend.url', config('app.url')), '/');
    $name = $user->display_name ?: $user->name ?: 'Learner';

    $this->safeDispatch(
      'auth.learner.registered',
      'learning',
      [
        'learner_name' => $name,
        'member_name' => $name,
        'email' => $user->email,
        'login_url' => $frontend.'/login',
        'dashboard_url' => $frontend.'/learn',
        'in_app_title' => 'Welcome to learning',
        'in_app_body' => 'Your learner account is ready.',
      ],
      $user,
      $user->email,
      $name,
      $user,
      "auth.learner.registered:{$user->uuid}",
      false,
    );
  }

  public function notifyEnrollment(Enrollment $enrollment): void
  {
    $enrollment->loadMissing(['course', 'user']);
    $user = $enrollment->user;
    $course = $enrollment->course;
    if ($user === null || $course === null) {
      return;
    }

    $frontend = rtrim((string) config('app-frontend.url', config('app.url')), '/');
    $name = $user->display_name ?: $user->name ?: 'Learner';

    $this->safeDispatch(
      'lms.enrollment.created',
      'learning',
      [
        'learner_name' => $name,
        'member_name' => $name,
        'course_name' => $course->title,
        'course_title' => $course->title,
        'course_url' => $frontend.'/learn/courses/'.$enrollment->uuid,
        'dashboard_url' => $frontend.'/learn',
        'in_app_title' => 'Enrolled: '.$course->title,
        'in_app_body' => 'Your enrollment is '.($enrollment->status?->value ?? 'active'),
      ],
      $user,
      $user->email,
      $name,
      $enrollment,
      "lms.enrollment.created:{$enrollment->uuid}",
      true,
    );
  }

  public function notifySchoolEnrollment(SchoolEnrollment $enrollment, bool $activated = false): void
  {
    $enrollment->loadMissing(['school', 'user']);
    $user = $enrollment->user;
    $school = $enrollment->school;
    if ($user === null || $school === null) {
      return;
    }

    $frontend = rtrim((string) config('app-frontend.url', config('app.url')), '/');
    $name = $user->display_name ?: $user->name ?: 'Learner';
    $eventKey = $activated ? 'lms.school.enrollment.activated' : 'lms.school.enrollment.created';

    $this->safeDispatch(
      $eventKey,
      'learning',
      [
        'learner_name' => $name,
        'member_name' => $name,
        'school_name' => $school->title,
        'dashboard_url' => $frontend.'/learn',
        'in_app_title' => $activated ? 'School enrollment active' : 'School enrollment recorded',
        'in_app_body' => $school->title,
      ],
      $user,
      $user->email,
      $name,
      $enrollment,
      "{$eventKey}:{$enrollment->uuid}",
      true,
    );
  }

  public function notifyModuleCompleted(User $user, LmsProgramModule $module): void
  {
    $module->loadMissing(['school', 'category']);
    $name = $user->display_name ?: $user->name ?: 'Learner';
    $frontend = rtrim((string) config('app-frontend.url', config('app.url')), '/');

    $this->safeDispatch(
      'lms.module.completed',
      'learning',
      [
        'learner_name' => $name,
        'member_name' => $name,
        'module_name' => $module->title,
        'school_name' => $module->school?->title ?? $module->category?->name ?? '',
        'dashboard_url' => $frontend.'/learn',
        'in_app_title' => 'Module completed: '.$module->title,
        'in_app_body' => $module->title,
      ],
      $user,
      $user->email,
      $name,
      $module,
      "lms.module.completed:{$user->uuid}:{$module->uuid}",
      true,
    );
  }

  public function notifyCourseCompleted(Enrollment $enrollment): void
  {
    $enrollment->loadMissing(['course', 'user']);
    $user = $enrollment->user;
    $course = $enrollment->course;
    if ($user === null || $course === null) {
      return;
    }

    $name = $user->display_name ?: $user->name ?: 'Learner';
    $frontend = rtrim((string) config('app-frontend.url', config('app.url')), '/');

    $this->safeDispatch(
      'lms.course.completed',
      'learning',
      [
        'learner_name' => $name,
        'member_name' => $name,
        'course_name' => $course->title,
        'course_title' => $course->title,
        'dashboard_url' => $frontend.'/learn',
        'in_app_title' => 'Completed: '.$course->title,
        'in_app_body' => 'Congratulations on completing this course.',
      ],
      $user,
      $user->email,
      $name,
      $enrollment,
      "lms.course.completed:{$enrollment->uuid}",
      true,
    );
  }

  public function notifyAssessmentSubmitted(AssessmentAttempt $attempt): void
  {
    $attempt->loadMissing(['user', 'assessment.course']);
    $user = $attempt->user;
    if ($user === null) {
      return;
    }

    $name = $user->display_name ?: $user->name ?: 'Learner';
    $this->safeDispatch(
      'lms.assessment.submitted',
      'learning',
      $this->assessmentVars($attempt, $name),
      $user,
      $user->email,
      $name,
      $attempt,
      "lms.assessment.submitted:{$attempt->uuid}",
      false,
    );
  }

  public function notifyAssessmentResult(AssessmentAttempt $attempt): void
  {
    $attempt->loadMissing(['user', 'assessment.course']);
    $user = $attempt->user;
    if ($user === null) {
      return;
    }

    $name = $user->display_name ?: $user->name ?: 'Learner';
    $eventKey = $attempt->passed ? 'lms.assessment.passed' : 'lms.assessment.failed';
    if ((string) ($attempt->status->value ?? $attempt->status) === 'grading') {
      $eventKey = 'lms.assessment.submitted';
    }

    $this->safeDispatch(
      $eventKey,
      'learning',
      $this->assessmentVars($attempt, $name),
      $user,
      $user->email,
      $name,
      $attempt,
      "lms.assessment.result:{$attempt->uuid}",
      true,
    );
  }

  public function notifyTranscriptAvailable(User $user): void
  {
    $frontend = rtrim((string) config('app-frontend.url', config('app.url')), '/');
    $name = $user->display_name ?: $user->name ?: 'Learner';

    $this->safeDispatch(
      'lms.transcript.available',
      'learning',
      [
        'learner_name' => $name,
        'member_name' => $name,
        'dashboard_url' => $frontend.'/learn',
        'result_url' => $frontend.'/learn/transcript',
        'in_app_title' => 'Your transcript is available',
        'in_app_body' => 'View your learning transcript in the learner dashboard.',
      ],
      $user,
      $user->email,
      $name,
      $user,
      "lms.transcript.available:{$user->uuid}",
      false,
    );
  }

  public function notifyCoursePaymentConfirmed(CourseOrder $order, Donation $donation): void
  {
    $order->loadMissing(['course', 'user']);
    $user = $order->user;
    $course = $order->course;
    if ($user === null || $course === null) {
      return;
    }

    $name = $user->display_name ?: $user->name ?: 'Learner';
    $this->safeDispatch(
      'lms.payment.confirmed',
      'payments',
      [
        'learner_name' => $name,
        'member_name' => $name,
        'course_name' => $course->title,
        'amount' => number_format((float) $order->amount, 2),
        'currency' => $order->currency ?? 'USD',
        'payment_reference' => $donation->reference,
        'in_app_title' => 'Payment confirmed',
        'in_app_body' => $course->title,
      ],
      $user,
      $user->email,
      $name,
      $order,
      "lms.payment.confirmed:{$order->uuid}",
      true,
    );
  }

  public function notifySchoolPaymentConfirmed(SchoolOrder $order, Donation $donation): void
  {
    $order->loadMissing(['school', 'user', 'schoolEnrollment']);
    $user = $order->user;
    $school = $order->school;
    if ($user === null || $school === null) {
      return;
    }

    $name = $user->display_name ?: $user->name ?: 'Learner';
    $this->safeDispatch(
      'lms.school.payment.confirmed',
      'payments',
      [
        'learner_name' => $name,
        'member_name' => $name,
        'school_name' => $school->title,
        'amount' => number_format((float) $order->amount, 2),
        'currency' => $order->currency ?? 'USD',
        'payment_reference' => $donation->reference,
        'in_app_title' => 'School payment confirmed',
        'in_app_body' => $school->title,
      ],
      $user,
      $user->email,
      $name,
      $order,
      "lms.school.payment.confirmed:{$order->uuid}",
      true,
    );

    if ($order->schoolEnrollment) {
      $this->notifySchoolEnrollment($order->schoolEnrollment, true);
    }
  }

  public function notifyOfflinePaymentSubmitted(User $user, string $itemName, ?string $reference = null): void
  {
    $name = $user->display_name ?: $user->name ?: 'Learner';
    $this->safeDispatch(
      'lms.payment.offline.submitted',
      'payments',
      [
        'learner_name' => $name,
        'member_name' => $name,
        'course_name' => $itemName,
        'payment_reference' => $reference ?? '',
        'in_app_title' => 'Offline payment submitted',
        'in_app_body' => $itemName,
      ],
      $user,
      $user->email,
      $name,
      null,
      'lms.payment.offline.submitted:'.$user->uuid.':'.($reference ?? 'none'),
      true,
    );
  }

  public function notifyPaymentRejected(User $user, string $itemName, ?string $reference = null, ?string $reason = null): void
  {
    $name = $user->display_name ?: $user->name ?: 'Learner';
    $this->safeDispatch(
      'lms.payment.rejected',
      'payments',
      [
        'learner_name' => $name,
        'member_name' => $name,
        'course_name' => $itemName,
        'payment_reference' => $reference ?? '',
        'reason' => $reason ?? 'Payment could not be verified.',
        'in_app_title' => 'Payment not confirmed',
        'in_app_body' => $itemName,
      ],
      $user,
      $user->email,
      $name,
      null,
      'lms.payment.rejected:'.$user->uuid.':'.($reference ?? 'none'),
      true,
    );
  }

  public function notifyRefund(CourseOrder $order, CourseRefund $refund): void
  {
    $order->loadMissing(['course', 'user']);
    $user = $order->user;
    if ($user === null) {
      return;
    }

    $name = $user->display_name ?: $user->name ?: 'Learner';
    $this->safeDispatch(
      'lms.payment.refunded',
      'payments',
      [
        'learner_name' => $name,
        'member_name' => $name,
        'course_name' => $order->course?->title ?? 'Course',
        'amount' => number_format((float) $refund->amount, 2),
        'currency' => $refund->currency ?? 'USD',
        'payment_reference' => $order->order_number,
        'reason' => $refund->reason ?? '',
        'in_app_title' => 'Refund processed',
        'in_app_body' => $order->course?->title ?? 'Course',
      ],
      $user,
      $user->email,
      $name,
      $refund,
      "lms.payment.refunded:{$refund->uuid}",
      true,
    );
  }

  public function notifyAssignmentGraded(AssignmentSubmission $submission): void
  {
    $submission->loadMissing(['user', 'assignment.course']);
    $user = $submission->user;
    if ($user === null) {
      return;
    }

    $name = $user->display_name ?: $user->name ?: 'Learner';
    $this->safeDispatch(
      'lms.assignment.graded',
      'learning',
      [
        'learner_name' => $name,
        'member_name' => $name,
        'assignment_title' => $submission->assignment?->title ?? 'Assignment',
        'course_name' => $submission->assignment?->course?->title ?? '',
        'score' => $submission->score,
        'in_app_title' => 'Assignment graded',
        'in_app_body' => (string) ($submission->teacher_comments ?? $submission->assignment?->title ?? ''),
      ],
      $user,
      $user->email,
      $name,
      $submission,
      "lms.assignment.graded:{$submission->uuid}",
      false,
    );
  }

  /** @return array<string, mixed> */
  private function assessmentVars(AssessmentAttempt $attempt, string $name): array
  {
    $frontend = rtrim((string) config('app-frontend.url', config('app.url')), '/');

    return [
      'learner_name' => $name,
      'member_name' => $name,
      'assessment_name' => $attempt->assessment?->title ?? 'Assessment',
      'course_name' => $attempt->assessment?->course?->title ?? '',
      'score' => $attempt->score !== null ? (string) $attempt->score : '',
      'percentage' => $attempt->percentage !== null ? number_format((float) $attempt->percentage, 1).'%' : '',
      'pass_status' => $attempt->passed ? 'Passed' : 'Not passed',
      'result_url' => $frontend.'/learn',
      'dashboard_url' => $frontend.'/learn',
      'in_app_title' => 'Assessment: '.($attempt->assessment?->title ?? 'Assessment'),
      'in_app_body' => $attempt->remarks ?? ($attempt->passed ? 'Passed' : 'Result available'),
    ];
  }

  /**
   * @param  array<string, mixed>  $variables
   */
  private function safeDispatch(
    string $eventKey,
    string $section,
    array $variables,
    ?User $user = null,
    ?string $email = null,
    ?string $name = null,
    ?\Illuminate\Database\Eloquent\Model $related = null,
    ?string $idempotencyKey = null,
    bool $includeRouting = true,
  ): void {
    try {
      $this->dispatch->dispatchEvent(
        eventKey: $eventKey,
        section: $section,
        variables: $variables,
        recipientUser: $user,
        recipientEmail: $email,
        recipientName: $name,
        related: $related,
        includeRouting: $includeRouting,
        idempotencyKey: $idempotencyKey,
      );
    } catch (\Throwable $exception) {
      report($exception);
    }
  }
}
