<?php

declare(strict_types=1);

namespace App\Modules\Lms\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\ApiController;
use App\Modules\Lms\Models\Assessment;
use App\Modules\Lms\Models\AssessmentAttempt;
use App\Modules\Lms\Models\Question;
use App\Modules\Lms\Services\AssessmentAdminService;
use App\Modules\Lms\Services\AssessmentAttemptService;
use App\Modules\Lms\Services\QuestionBankImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class AssessmentAdminController extends ApiController
{
  public function questions(Request $request, AssessmentAdminService $service): JsonResponse
  {
    $this->authorize('viewAny', Assessment::class);
    $paginator = $service->paginateQuestions($request->query());

    return $this->responder->success(
      data: [
        'data' => collect($paginator->items())->map(fn (Question $q) => $this->questionPayload($q)),
        'meta' => [
          'current_page' => $paginator->currentPage(),
          'last_page' => $paginator->lastPage(),
          'per_page' => $paginator->perPage(),
          'total' => $paginator->total(),
        ],
      ],
      message: 'Question bank retrieved.',
    );
  }

  public function storeQuestion(Request $request, AssessmentAdminService $service): JsonResponse
  {
    $this->authorize('create', Assessment::class);
    $validated = $request->validate([
      'prompt' => ['required', 'string', 'max:1000'],
      'stem' => ['nullable', 'string'],
      'question_type' => ['required', 'string', Rule::in([
        'multiple_choice', 'checkbox', 'true_false', 'essay', 'matching', 'ordering', 'fill_blank',
      ])],
      'default_points' => ['nullable', 'numeric', 'min:0'],
      'correct_payload' => ['nullable', 'array'],
      'explanation' => ['nullable', 'string'],
      'difficulty' => ['nullable', 'string', 'max:40'],
      'status' => ['nullable', 'string', Rule::in(['active', 'inactive'])],
      'metadata' => ['nullable', 'array'],
      'metadata.topic' => ['nullable', 'string', 'max:120'],
      'metadata.level' => ['nullable', 'string', 'max:120'],
      'metadata.tags' => ['nullable', 'array'],
      'metadata.tags.*' => ['string', 'max:60'],
      'metadata.course_id' => ['nullable', 'uuid'],
      'metadata.ministry_id' => ['nullable', 'uuid'],
      'metadata.negative_marking' => ['nullable', 'boolean'],
      'metadata.negative_mark_value' => ['nullable', 'numeric', 'min:0'],
      'metadata.rubric' => ['nullable', 'string'],
      'metadata.requires_attachment' => ['nullable', 'boolean'],
      'metadata.response_modes' => ['nullable', 'array'],
      'options' => ['sometimes', 'array'],
      'options.*.label' => ['nullable', 'string', 'max:20'],
      'options.*.body' => ['nullable', 'string'],
      'options.*.match_key' => ['nullable', 'string', 'max:120'],
      'options.*.is_correct' => ['sometimes', 'boolean'],
      'options.*.sort_order' => ['sometimes', 'integer', 'min:0'],
    ]);

    if (($validated['question_type'] ?? '') === 'multiple_choice') {
      $options = $validated['options'] ?? [];
      if (count($options) < 3) {
        return $this->responder->error(
          message: 'Objective (multiple choice) questions require at least 3 options.',
          code: 'validation_error',
          status: 422,
        );
      }
      $correctCount = collect($options)->where('is_correct', true)->count();
      if ($correctCount !== 1) {
        return $this->responder->error(
          message: 'Objective questions require exactly one correct answer.',
          code: 'validation_error',
          status: 422,
        );
      }
    }

    $question = $service->createQuestion($validated, $request->user());

    return $this->responder->success(
      data: ['question' => $this->questionPayload($question)],
      message: 'Question created.',
      status: 201,
    );
  }

  public function updateQuestion(Request $request, Question $question, AssessmentAdminService $service): JsonResponse
  {
    $this->authorize('update', $question);
    $validated = $request->validate([
      'prompt' => ['sometimes', 'string', 'max:1000'],
      'stem' => ['sometimes', 'nullable', 'string'],
      'question_type' => ['sometimes', 'string', Rule::in([
        'multiple_choice', 'checkbox', 'true_false', 'essay', 'matching', 'ordering', 'fill_blank',
      ])],
      'default_points' => ['sometimes', 'numeric', 'min:0'],
      'correct_payload' => ['sometimes', 'nullable', 'array'],
      'explanation' => ['sometimes', 'nullable', 'string'],
      'difficulty' => ['sometimes', 'nullable', 'string', 'max:40'],
      'status' => ['sometimes', 'string', Rule::in(['active', 'inactive'])],
      'options' => ['sometimes', 'array'],
    ]);
    $question = $service->updateQuestion($question, $validated, $request->user());

    return $this->responder->success(
      data: ['question' => $this->questionPayload($question)],
      message: 'Question updated.',
    );
  }

  public function destroyQuestion(Question $question): JsonResponse
  {
    $this->authorize('delete', $question);
    $question->delete();

    return $this->responder->success(message: 'Question deleted.');
  }

  public function questionImportTemplate(Request $request, QuestionBankImportService $import): mixed
  {
    $this->authorize('create', Assessment::class);
    $format = strtolower((string) $request->query('format', 'csv'));

    return $import->downloadTemplate($format === 'xlsx' ? 'xlsx' : 'csv');
  }

  public function importQuestions(Request $request, QuestionBankImportService $import): JsonResponse
  {
    $this->authorize('create', Assessment::class);
    $validated = $request->validate([
      'file' => ['required', 'file', 'mimes:csv,txt,xlsx,xls', 'max:10240'],
      'dry_run' => ['sometimes', 'boolean'],
    ]);

    $dryRun = array_key_exists('dry_run', $validated)
      ? (bool) $validated['dry_run']
      : filter_var($request->input('dry_run', true), FILTER_VALIDATE_BOOLEAN);

    $report = $import->import($request->file('file'), $dryRun, $request->user());

    return $this->responder->success(
      data: $report,
      message: $dryRun ? 'Question bank dry-run completed.' : 'Question bank import completed.',
    );
  }

  public function index(Request $request, AssessmentAdminService $service): JsonResponse
  {
    $this->authorize('viewAny', Assessment::class);
    $paginator = $service->paginateAssessments($request->query());

    return $this->responder->success(
      data: [
        'data' => collect($paginator->items())->map(fn (Assessment $a) => $this->assessmentPayload($a)),
        'meta' => [
          'current_page' => $paginator->currentPage(),
          'last_page' => $paginator->lastPage(),
          'per_page' => $paginator->perPage(),
          'total' => $paginator->total(),
        ],
      ],
      message: 'Assessments retrieved.',
    );
  }

  public function store(Request $request, AssessmentAdminService $service): JsonResponse
  {
    $this->authorize('create', Assessment::class);
    $validated = $this->assessmentRules($request);
    $assessment = $service->createAssessment($validated, $request->user());

    return $this->responder->success(
      data: ['assessment' => $this->assessmentPayload($assessment, true)],
      message: 'Assessment created.',
      status: 201,
    );
  }

  public function show(Assessment $assessment): JsonResponse
  {
    $this->authorize('view', $assessment);
    $assessment->load(['questions.options', 'course', 'lesson']);

    return $this->responder->success(
      data: ['assessment' => $this->assessmentPayload($assessment, true)],
      message: 'Assessment retrieved.',
    );
  }

  public function update(Request $request, Assessment $assessment, AssessmentAdminService $service): JsonResponse
  {
    $this->authorize('update', $assessment);
    $validated = $this->assessmentRules($request, true);
    $assessment = $service->updateAssessment($assessment, $validated, $request->user());

    return $this->responder->success(
      data: ['assessment' => $this->assessmentPayload($assessment, true)],
      message: 'Assessment updated.',
    );
  }

  public function destroy(Assessment $assessment): JsonResponse
  {
    $this->authorize('delete', $assessment);
    $assessment->delete();

    return $this->responder->success(message: 'Assessment deleted.');
  }

  public function gradingQueue(Request $request): JsonResponse
  {
    $this->authorize('grade', Assessment::class);
    $rows = AssessmentAttempt::query()
      ->where('status', 'grading')
      ->with(['assessment:id,uuid,title', 'user:id,uuid,name,email', 'answers'])
      ->latest('submitted_at')
      ->paginate(min(100, max(1, (int) $request->query('per_page', 25))));

    return $this->responder->success(
      data: [
        'data' => collect($rows->items())->map(fn (AssessmentAttempt $a) => [
          'id' => $a->uuid,
          'status' => $a->status instanceof \BackedEnum ? $a->status->value : $a->status,
          'submitted_at' => $a->submitted_at?->toIso8601String(),
          'assessment' => $a->assessment ? [
            'id' => $a->assessment->uuid,
            'title' => $a->assessment->title,
          ] : null,
          'user' => $a->user ? [
            'id' => $a->user->uuid,
            'name' => $a->user->name,
            'email' => $a->user->email,
          ] : null,
          'pending_answers' => $a->answers
            ->filter(fn ($ans) => $ans->needs_manual_grading)
            ->map(fn ($ans) => [
              'id' => $ans->uuid,
              'points_possible' => (float) $ans->points_possible,
            ])
            ->values(),
        ]),
        'meta' => [
          'current_page' => $rows->currentPage(),
          'last_page' => $rows->lastPage(),
          'per_page' => $rows->perPage(),
          'total' => $rows->total(),
        ],
      ],
      message: 'Grading queue retrieved.',
    );
  }

  public function gradeAttempt(Request $request, AssessmentAttempt $attempt, AssessmentAttemptService $service): JsonResponse
  {
    $this->authorize('grade', Assessment::class);
    $validated = $request->validate([
      'grades' => ['required', 'array', 'min:1'],
      'grades.*.answer_id' => ['required', 'uuid'],
      'grades.*.points_awarded' => ['required', 'numeric', 'min:0'],
      'grades.*.feedback' => ['nullable', 'string'],
      'remarks' => ['nullable', 'string'],
    ]);

    $attempt = $service->instructorGrade($attempt, $validated['grades'], $request->user(), $validated['remarks'] ?? null);

    return $this->responder->success(
      data: ['result' => $service->resultPayload($attempt)],
      message: 'Attempt graded.',
    );
  }

  /** @return array<string, mixed> */
  private function assessmentRules(Request $request, bool $update = false): array
  {
    $req = $update ? 'sometimes' : 'required';

    return $request->validate([
      'title' => [$req, 'string', 'max:255'],
      'slug' => ['nullable', 'string', 'max:255'],
      'description' => ['nullable', 'string'],
      'course_id' => ['nullable', 'uuid'],
      'module_id' => ['nullable', 'uuid'],
      'lesson_id' => ['nullable', 'uuid'],
      'assessment_type' => [$update ? 'sometimes' : 'nullable', 'string', Rule::in(['quiz', 'assignment', 'timed_test', 'examination'])],
      'status' => ['nullable', 'string', Rule::in(['draft', 'published', 'archived'])],
      'pass_mark' => ['nullable', 'numeric', 'min:0', 'max:100'],
      'time_limit_seconds' => ['nullable', 'integer', 'min:30'],
      'max_attempts' => ['nullable', 'integer', 'min:1'],
      'retake_cooldown_minutes' => ['nullable', 'integer', 'min:0'],
      'randomize_questions' => ['sometimes', 'boolean'],
      'random_question_count' => ['nullable', 'integer', 'min:1'],
      'negative_marking' => ['sometimes', 'boolean'],
      'negative_mark_value' => ['nullable', 'numeric', 'min:0'],
      'show_immediate_result' => ['sometimes', 'boolean'],
      'allow_review' => ['sometimes', 'boolean'],
      'requires_instructor_grading' => ['sometimes', 'boolean'],
      'question_ids' => ['sometimes', 'array'],
    ]);
  }

  /** @return array<string, mixed> */
  private function questionPayload(Question $question): array
  {
    return [
      'id' => $question->uuid,
      'prompt' => $question->prompt,
      'stem' => $question->stem,
      'question_type' => $question->question_type instanceof \BackedEnum ? $question->question_type->value : $question->question_type,
      'default_points' => (float) $question->default_points,
      'correct_payload' => $question->correct_payload,
      'explanation' => $question->explanation,
      'difficulty' => $question->difficulty,
      'status' => $question->status,
      'metadata' => $question->metadata,
      'options' => $question->relationLoaded('options') ? $question->options->map(fn ($o) => [
        'id' => $o->uuid,
        'label' => $o->label,
        'body' => $o->body,
        'match_key' => $o->match_key,
        'is_correct' => (bool) $o->is_correct,
        'sort_order' => (int) $o->sort_order,
      ]) : [],
    ];
  }

  /** @return array<string, mixed> */
  private function assessmentPayload(Assessment $assessment, bool $withQuestions = false): array
  {
    $payload = [
      'id' => $assessment->uuid,
      'title' => $assessment->title,
      'slug' => $assessment->slug,
      'description' => $assessment->description,
      'assessment_type' => $assessment->assessment_type instanceof \BackedEnum
        ? $assessment->assessment_type->value
        : $assessment->assessment_type,
      'status' => $assessment->status instanceof \BackedEnum ? $assessment->status->value : $assessment->status,
      'pass_mark' => (float) $assessment->pass_mark,
      'time_limit_seconds' => $assessment->time_limit_seconds,
      'max_attempts' => $assessment->max_attempts,
      'retake_cooldown_minutes' => $assessment->retake_cooldown_minutes,
      'randomize_questions' => (bool) $assessment->randomize_questions,
      'random_question_count' => $assessment->random_question_count,
      'negative_marking' => (bool) $assessment->negative_marking,
      'negative_mark_value' => (float) $assessment->negative_mark_value,
      'show_immediate_result' => (bool) $assessment->show_immediate_result,
      'allow_review' => (bool) $assessment->allow_review,
      'requires_instructor_grading' => (bool) $assessment->requires_instructor_grading,
      'questions_count' => $assessment->questions_count ?? ($assessment->relationLoaded('questions') ? $assessment->questions->count() : null),
      'course' => $assessment->relationLoaded('course') && $assessment->course ? [
        'id' => $assessment->course->uuid,
        'title' => $assessment->course->title,
      ] : null,
      'lesson' => $assessment->relationLoaded('lesson') && $assessment->lesson ? [
        'id' => $assessment->lesson->uuid,
        'title' => $assessment->lesson->title,
      ] : null,
    ];

    if ($withQuestions && $assessment->relationLoaded('questions')) {
      $payload['questions'] = $assessment->questions->map(fn (Question $q) => [
        'id' => $q->uuid,
        'prompt' => $q->prompt,
        'question_type' => $q->question_type instanceof \BackedEnum ? $q->question_type->value : $q->question_type,
        'points' => (float) ($q->pivot->points ?? $q->default_points),
      ]);
    }

    return $payload;
  }
}
