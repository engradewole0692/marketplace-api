<?php

declare(strict_types=1);

namespace App\Modules\Lms\Services;

use App\Contracts\ServiceContract;
use App\Models\User;
use App\Modules\Lms\Enums\AttemptStatus;
use App\Modules\Lms\Enums\QuestionType;
use App\Modules\Lms\Models\Assessment;
use App\Modules\Lms\Models\AssessmentAttempt;
use App\Modules\Lms\Models\AttemptAnswer;
use App\Modules\Lms\Models\Enrollment;
use App\Modules\Lms\Models\Lesson;
use App\Modules\Lms\Models\Question;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class AssessmentAttemptService implements ServiceContract
{
  public function __construct(
    private readonly GradingEngine $grading,
    private readonly ProgressService $progress,
    private readonly LearningExperienceService $experience,
    private readonly AssessmentNotificationService $notifications,
  ) {}

  public function start(Assessment $assessment, User $user, ?Enrollment $enrollment = null): AssessmentAttempt
  {
    abort_unless(
      (string) ($assessment->status->value ?? $assessment->status) === 'published',
      404,
    );

    $prior = AssessmentAttempt::query()
      ->where('assessment_id', $assessment->id)
      ->where('user_id', $user->id)
      ->orderByDesc('attempt_number')
      ->get();

    if ($assessment->max_attempts !== null && $prior->count() >= $assessment->max_attempts) {
      throw ValidationException::withMessages(['attempt' => 'Maximum attempts reached.']);
    }

    $last = $prior->first();
    if ($last && $assessment->retake_cooldown_minutes) {
      $anchor = $last->submitted_at ?? $last->graded_at ?? $last->started_at;
      if ($anchor && $anchor->copy()->addMinutes((int) $assessment->retake_cooldown_minutes)->isFuture()) {
        throw ValidationException::withMessages(['attempt' => 'Retake cooldown is still active.']);
      }
    }

    $inProgress = $prior->first(fn (AssessmentAttempt $a) => (string) ($a->status->value ?? $a->status) === 'in_progress');
    if ($inProgress) {
      if ($inProgress->expires_at && $inProgress->expires_at->isPast()) {
        $inProgress->forceFill(['status' => AttemptStatus::Expired])->save();
      } else {
        return $inProgress->load(['assessment', 'answers']);
      }
    }

    $questions = $assessment->questions()->with('options')->get();
    if ($assessment->randomize_questions) {
      $questions = $questions->shuffle();
      if ($assessment->random_question_count) {
        $questions = $questions->take((int) $assessment->random_question_count);
      }
    }

    $order = $questions->pluck('uuid')->values()->all();

    return AssessmentAttempt::query()->create([
      'assessment_id' => $assessment->id,
      'enrollment_id' => $enrollment?->id,
      'user_id' => $user->id,
      'attempt_number' => ($last?->attempt_number ?? 0) + 1,
      'status' => AttemptStatus::InProgress,
      'started_at' => now(),
      'expires_at' => $assessment->time_limit_seconds
        ? now()->addSeconds((int) $assessment->time_limit_seconds)
        : null,
      'question_order' => $order,
      'max_score' => $questions->sum(fn (Question $q) => (float) ($q->pivot->points ?? $q->default_points)),
    ]);
  }

  /**
   * @param  array<int, array{question_id: string, response?: array<string, mixed>}>  $answers
   */
  public function submit(AssessmentAttempt $attempt, array $answers, User $user): AssessmentAttempt
  {
    abort_unless($attempt->user_id === $user->id, 403);
    $status = (string) ($attempt->status->value ?? $attempt->status);
    abort_unless(in_array($status, ['in_progress', 'submitted'], true), 422);

    if ($attempt->expires_at && $attempt->expires_at->isPast() && $status === 'in_progress') {
      $attempt->forceFill(['status' => AttemptStatus::Expired])->save();
      throw ValidationException::withMessages(['attempt' => 'Time limit expired.']);
    }

    return DB::transaction(function () use ($attempt, $answers, $user): AssessmentAttempt {
      $assessment = $attempt->assessment()->with(['questions.options'])->firstOrFail();
      $questions = $assessment->questions->keyBy('uuid');
      $order = $attempt->question_order ?? $questions->keys()->all();

      $score = 0.0;
      $max = 0.0;
      $needsManual = false;

      foreach ($order as $questionUuid) {
        /** @var Question|null $question */
        $question = $questions->get($questionUuid);
        if (! $question) {
          continue;
        }
        $response = collect($answers)->firstWhere('question_id', $questionUuid)['response'] ?? [];
        $pointsPossible = (float) ($question->pivot->points ?? $question->default_points);
        $max += $pointsPossible;
        $result = $this->grading->gradeQuestion($question, $response, $pointsPossible, $assessment);
        if ($result['needs_manual']) {
          $needsManual = true;
        } else {
          $score += $result['points'];
        }

        AttemptAnswer::query()->updateOrCreate(
          ['attempt_id' => $attempt->id, 'question_id' => $question->id],
          [
            'response_payload' => $response,
            'is_correct' => $result['is_correct'],
            'points_awarded' => $result['needs_manual'] ? null : $result['points'],
            'points_possible' => $pointsPossible,
            'needs_manual_grading' => $result['needs_manual'],
          ],
        );
      }

      $manualRequired = $needsManual || $assessment->requires_instructor_grading
        || $assessment->questions->contains(fn (Question $q) => ($q->question_type instanceof QuestionType
          ? $q->question_type
          : QuestionType::tryFrom((string) $q->question_type)) === QuestionType::Essay);

      if ($manualRequired) {
        $attempt->forceFill([
          'status' => AttemptStatus::Grading,
          'submitted_at' => now(),
          'score' => max(0, $score),
          'max_score' => $max,
          'percentage' => $max > 0 ? round(($score / $max) * 100, 2) : 0,
          'remarks' => 'Submitted — awaiting instructor grading for essay/manual items.',
        ])->save();
      } else {
        $this->finalizeAuto($attempt->fresh(), $score, $max, $user);
      }

      $this->notifications->notifySubmitted($attempt->fresh(['assessment', 'user', 'enrollment']));

      return $attempt->fresh(['answers.question.options', 'assessment']);
    });
  }

  public function finalizeAuto(AssessmentAttempt $attempt, float $score, float $max, ?User $actor = null): AssessmentAttempt
  {
    $assessment = $attempt->assessment;
    $score = max(0, $score);
    $percentage = $max > 0 ? round(($score / $max) * 100, 2) : 0.0;
    $passed = $percentage >= (float) $assessment->pass_mark;
    $grade = $this->grading->letterGrade($percentage);
    $remarks = $this->grading->remarks($passed, $percentage);

    $attempt->forceFill([
      'status' => AttemptStatus::Graded,
      'submitted_at' => $attempt->submitted_at ?? now(),
      'graded_at' => now(),
      'score' => $score,
      'max_score' => $max,
      'percentage' => $percentage,
      'grade' => $grade,
      'passed' => $passed,
      'remarks' => $remarks,
      'graded_by_user_id' => $actor?->id,
    ])->save();

    $this->afterGraded($attempt->fresh(['assessment', 'user', 'enrollment']));

    return $attempt->fresh(['answers.question', 'assessment']);
  }

  /**
   * @param  array<int, array{answer_id: string, points_awarded: float, feedback?: string|null}>  $grades
   */
  public function instructorGrade(AssessmentAttempt $attempt, array $grades, User $instructor, ?string $remarks = null): AssessmentAttempt
  {
    return DB::transaction(function () use ($attempt, $grades, $instructor, $remarks): AssessmentAttempt {
      foreach ($grades as $row) {
        $answer = AttemptAnswer::query()
          ->where('uuid', $row['answer_id'])
          ->where('attempt_id', $attempt->id)
          ->firstOrFail();
        $points = min((float) $answer->points_possible, max(0, (float) $row['points_awarded']));
        $answer->forceFill([
          'points_awarded' => $points,
          'is_correct' => $points >= ((float) $answer->points_possible * 0.999),
          'needs_manual_grading' => false,
          'instructor_feedback' => $row['feedback'] ?? null,
          'graded_by_user_id' => $instructor->id,
          'graded_at' => now(),
        ])->save();
      }

      $attempt->load('answers');
      $score = (float) $attempt->answers->sum('points_awarded');
      $max = (float) $attempt->answers->sum('points_possible');
      $percentage = $max > 0 ? round(($score / $max) * 100, 2) : 0.0;
      $passed = $percentage >= (float) $attempt->assessment->pass_mark;

      $attempt->forceFill([
        'status' => AttemptStatus::Graded,
        'graded_at' => now(),
        'score' => $score,
        'max_score' => $max,
        'percentage' => $percentage,
        'grade' => $this->grading->letterGrade($percentage),
        'passed' => $passed,
        'remarks' => $remarks ?: $this->grading->remarks($passed, $percentage),
        'graded_by_user_id' => $instructor->id,
      ])->save();

      $this->afterGraded($attempt->fresh(['assessment', 'user', 'enrollment']));

      return $attempt->fresh(['answers.question', 'assessment']);
    });
  }

  private function afterGraded(AssessmentAttempt $attempt): void
  {
    $assessment = $attempt->assessment;
    if ($attempt->passed && $attempt->enrollment_id && $assessment->lesson_id) {
      $enrollment = Enrollment::query()->find($attempt->enrollment_id);
      $lesson = Lesson::query()->find($assessment->lesson_id);
      if ($enrollment && $lesson) {
        $this->progress->markLessonProgress($enrollment, $lesson, 100);
      }
    }

    // Course complete + assessment pass → auto-issue via shared certificate engine.
    if ($attempt->passed && $attempt->enrollment_id) {
      $enrollment = Enrollment::query()->find($attempt->enrollment_id);
      if ($enrollment) {
        $this->progress->maybeIssueCertificate($enrollment);
      }
    }

    $user = $attempt->user;
    if ($user) {
      $this->experience->recordActivity(
        $user,
        'assessment.graded',
        ($attempt->passed ? 'Passed' : 'Completed').': '.$assessment->title,
        $attempt->remarks,
        $assessment->course,
        $attempt->enrollment,
        $assessment->lesson,
        [
          'percentage' => (float) $attempt->percentage,
          'grade' => $attempt->grade,
          'attempt_id' => $attempt->uuid,
        ],
      );
      $this->notifications->notifyResult($attempt);
    }
  }

  /** @return array<string, mixed> */
  public function resultPayload(AssessmentAttempt $attempt, bool $includeAnswers = true): array
  {
    $attempt->loadMissing(['assessment', 'answers.question.options', 'user']);
    $assessment = $attempt->assessment;
    $show = $assessment->show_immediate_result || (string) ($attempt->status->value ?? $attempt->status) === 'graded';

    $payload = [
      'id' => $attempt->uuid,
      'attempt_number' => $attempt->attempt_number,
      'status' => $attempt->status instanceof \BackedEnum ? $attempt->status->value : $attempt->status,
      'started_at' => $attempt->started_at?->toIso8601String(),
      'submitted_at' => $attempt->submitted_at?->toIso8601String(),
      'expires_at' => $attempt->expires_at?->toIso8601String(),
      'graded_at' => $attempt->graded_at?->toIso8601String(),
      'score' => $show ? ($attempt->score !== null ? (float) $attempt->score : null) : null,
      'max_score' => $attempt->max_score !== null ? (float) $attempt->max_score : null,
      'percentage' => $show ? ($attempt->percentage !== null ? (float) $attempt->percentage : null) : null,
      'grade' => $show ? $attempt->grade : null,
      'passed' => $show ? $attempt->passed : null,
      'remarks' => $show ? $attempt->remarks : null,
      'assessment' => [
        'id' => $assessment->uuid,
        'title' => $assessment->title,
        'assessment_type' => $assessment->assessment_type instanceof \BackedEnum
          ? $assessment->assessment_type->value
          : $assessment->assessment_type,
        'pass_mark' => (float) $assessment->pass_mark,
        'allow_review' => (bool) $assessment->allow_review,
        'show_immediate_result' => (bool) $assessment->show_immediate_result,
      ],
      'printable' => $show,
    ];

    if ($includeAnswers && $show && $assessment->allow_review) {
      $payload['answers'] = $attempt->answers->map(fn (AttemptAnswer $a) => [
        'id' => $a->uuid,
        'question_id' => $a->question?->uuid,
        'prompt' => $a->question?->prompt,
        'question_type' => $a->question?->question_type instanceof \BackedEnum
          ? $a->question->question_type->value
          : $a->question?->question_type,
        'response' => $a->response_payload,
        'is_correct' => $a->is_correct,
        'points_awarded' => $a->points_awarded !== null ? (float) $a->points_awarded : null,
        'points_possible' => $a->points_possible !== null ? (float) $a->points_possible : null,
        'needs_manual_grading' => (bool) $a->needs_manual_grading,
        'instructor_feedback' => $a->instructor_feedback,
        'explanation' => $a->question?->explanation,
      ]);
    }

    return $payload;
  }

  public function takePayload(AssessmentAttempt $attempt): array
  {
    $attempt->loadMissing(['assessment.questions.options']);
    $assessment = $attempt->assessment;
    $order = $attempt->question_order ?? [];
    $questions = $assessment->questions->keyBy('uuid');

    $items = collect($order)->map(function (string $uuid) use ($questions) {
      /** @var Question|null $q */
      $q = $questions->get($uuid);
      if (! $q) {
        return null;
      }

      return [
        'id' => $q->uuid,
        'prompt' => $q->prompt,
        'stem' => $q->stem,
        'question_type' => $q->question_type instanceof \BackedEnum ? $q->question_type->value : $q->question_type,
        'points' => (float) ($q->pivot->points ?? $q->default_points),
        'options' => $q->options->map(fn ($o) => [
          'id' => $o->uuid,
          'label' => $o->label,
          'body' => $o->body,
          'match_key' => $o->match_key,
          // Never expose is_correct to learners mid-attempt
        ])->values(),
      ];
    })->filter()->values();

    return [
      'attempt' => [
        'id' => $attempt->uuid,
        'attempt_number' => $attempt->attempt_number,
        'status' => $attempt->status instanceof \BackedEnum ? $attempt->status->value : $attempt->status,
        'started_at' => $attempt->started_at?->toIso8601String(),
        'expires_at' => $attempt->expires_at?->toIso8601String(),
        'time_limit_seconds' => $assessment->time_limit_seconds,
      ],
      'assessment' => [
        'id' => $assessment->uuid,
        'title' => $assessment->title,
        'description' => $assessment->description,
        'assessment_type' => $assessment->assessment_type instanceof \BackedEnum
          ? $assessment->assessment_type->value
          : $assessment->assessment_type,
        'pass_mark' => (float) $assessment->pass_mark,
        'negative_marking' => (bool) $assessment->negative_marking,
      ],
      'questions' => $items,
    ];
  }
}
