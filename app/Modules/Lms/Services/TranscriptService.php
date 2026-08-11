<?php

declare(strict_types=1);

namespace App\Modules\Lms\Services;

use App\Contracts\ServiceContract;
use App\Models\User;
use App\Modules\Lms\Models\AssessmentAttempt;
use App\Modules\Lms\Models\AssignmentSubmission;
use App\Modules\Lms\Models\CourseCertificate;
use App\Modules\Lms\Models\Enrollment;
use App\Modules\Lms\Models\SchoolEnrollment;
use App\Modules\Communications\Services\CommunicationLmsBridge;

final class TranscriptService implements ServiceContract
{
  public function __construct(
    private readonly CommunicationLmsBridge $communicationLms,
  ) {}

  /** @return array<string, mixed> */
  public function forUser(User $user, bool $notifyIfAvailable = false): array
  {
    $schoolEnrollments = SchoolEnrollment::query()
      ->where('user_id', $user->id)
      ->with(['school'])
      ->latest('enrolled_at')
      ->get()
      ->map(fn (SchoolEnrollment $e) => [
        'id' => $e->uuid,
        'school' => $e->school?->title,
        'school_slug' => $e->school?->slug,
        'status' => $e->status instanceof \BackedEnum ? $e->status->value : $e->status,
        'progress_percent' => $e->progress_percent !== null ? (float) $e->progress_percent : 0,
        'enrolled_at' => $e->enrolled_at?->toIso8601String(),
        'completed_at' => $e->completed_at?->toIso8601String(),
      ]);

    $courseEnrollments = Enrollment::query()
      ->where('user_id', $user->id)
      ->with(['course.school', 'course.modules'])
      ->latest('enrolled_at')
      ->get()
      ->map(fn (Enrollment $e) => [
        'id' => $e->uuid,
        'course' => $e->course?->title,
        'course_slug' => $e->course?->slug,
        'school' => $e->course?->school?->title,
        'status' => $e->status instanceof \BackedEnum ? $e->status->value : $e->status,
        'progress_percent' => $e->progress_percent !== null ? (float) $e->progress_percent : 0,
        'enrolled_at' => $e->enrolled_at?->toIso8601String(),
        'completed_at' => $e->completed_at?->toIso8601String(),
      ]);

    $assessments = AssessmentAttempt::query()
      ->where('user_id', $user->id)
      ->whereIn('status', ['graded', 'submitted', 'grading'])
      ->with(['assessment:id,uuid,title,assessment_type,pass_mark'])
      ->latest('submitted_at')
      ->limit(100)
      ->get()
      ->map(fn (AssessmentAttempt $a) => [
        'id' => $a->uuid,
        'assessment' => $a->assessment?->title,
        'assessment_type' => $a->assessment?->assessment_type instanceof \BackedEnum
          ? $a->assessment->assessment_type->value
          : $a->assessment?->assessment_type,
        'attempt_number' => $a->attempt_number,
        'status' => $a->status instanceof \BackedEnum ? $a->status->value : $a->status,
        'percentage' => $a->percentage !== null ? (float) $a->percentage : null,
        'grade' => $a->grade,
        'passed' => $a->passed,
        'remarks' => $a->remarks,
        'submitted_at' => $a->submitted_at?->toIso8601String(),
      ]);

    $assignments = AssignmentSubmission::query()
      ->where('user_id', $user->id)
      ->with(['assignment:id,uuid,title'])
      ->latest('submitted_at')
      ->limit(100)
      ->get()
      ->map(fn (AssignmentSubmission $s) => [
        'id' => $s->uuid,
        'assignment' => $s->assignment?->title,
        'status' => $s->status instanceof \BackedEnum ? $s->status->value : $s->status,
        'score' => $s->score !== null ? (float) $s->score : null,
        'max_score' => $s->max_score !== null ? (float) $s->max_score : null,
        'feedback' => $s->teacher_comments,
        'submitted_at' => $s->submitted_at?->toIso8601String(),
        'graded_at' => $s->graded_at?->toIso8601String(),
      ]);

    $certificates = CourseCertificate::query()
      ->where('user_id', $user->id)
      ->where('status', 'issued')
      ->with(['course:id,uuid,title', 'template:id,uuid,name', 'certificateMedia'])
      ->latest('issued_at')
      ->limit(50)
      ->get()
      ->map(fn (CourseCertificate $c) => [
        'id' => $c->uuid,
        'certificate_number' => $c->certificate_number,
        'course' => $c->course?->title,
        'template' => $c->template?->name,
        'issued_at' => $c->issued_at?->toIso8601String(),
        'verification_code' => $c->verification_code,
        'certificate_url' => $c->certificateMedia?->url(),
      ]);

    $payload = [
      'schools' => $schoolEnrollments,
      'courses' => $courseEnrollments,
      'assessments' => $assessments,
      'assignments' => $assignments,
      'certificates' => $certificates,
    ];

    if ($notifyIfAvailable && (
      $schoolEnrollments->isNotEmpty()
      || $courseEnrollments->isNotEmpty()
      || $assessments->isNotEmpty()
      || $assignments->isNotEmpty()
      || $certificates->isNotEmpty()
    )) {
      $this->communicationLms->notifyTranscriptAvailable($user);
    }

    return $payload;
  }
}
