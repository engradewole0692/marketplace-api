<?php

declare(strict_types=1);

namespace App\Modules\Lms\Services;

use App\Contracts\ServiceContract;
use App\Models\User;
use App\Modules\Lms\Enums\AssessmentStatus;
use App\Modules\Lms\Enums\AssessmentType;
use App\Modules\Lms\Enums\QuestionType;
use App\Modules\Lms\Models\Assessment;
use App\Modules\Lms\Models\Course;
use App\Modules\Lms\Models\Lesson;
use App\Modules\Lms\Models\Question;
use App\Modules\Lms\Models\QuestionOption;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class AssessmentAdminService implements ServiceContract
{
  /** @param  array<string, mixed>  $filters */
  public function paginateQuestions(array $filters = []): LengthAwarePaginator
  {
    $query = Question::query()->with('options')->latest();
    if (! empty($filters['search'])) {
      $search = (string) $filters['search'];
      $query->where(function ($q) use ($search): void {
        $q->where('prompt', 'like', "%{$search}%")->orWhere('stem', 'like', "%{$search}%");
      });
    }
    if (! empty($filters['question_type'])) {
      $query->where('question_type', $filters['question_type']);
    }

    return $query->paginate(min(100, max(1, (int) ($filters['per_page'] ?? 25))));
  }

  /** @param  array<string, mixed>  $data */
  public function createQuestion(array $data, ?User $actor = null): Question
  {
    return DB::transaction(function () use ($data, $actor): Question {
      $question = Question::query()->create([
        'prompt' => $data['prompt'],
        'stem' => $data['stem'] ?? null,
        'question_type' => QuestionType::from($data['question_type']),
        'default_points' => $data['default_points'] ?? 1,
        'correct_payload' => $data['correct_payload'] ?? null,
        'metadata' => $data['metadata'] ?? null,
        'difficulty' => $data['difficulty'] ?? null,
        'status' => $data['status'] ?? 'active',
        'explanation' => $data['explanation'] ?? null,
        'created_by_user_id' => $actor?->id,
        'updated_by_user_id' => $actor?->id,
      ]);
      $this->syncOptions($question, $data['options'] ?? []);

      return $question->load('options');
    });
  }

  /** @param  array<string, mixed>  $data */
  public function updateQuestion(Question $question, array $data, ?User $actor = null): Question
  {
    return DB::transaction(function () use ($question, $data, $actor): Question {
      $payload = collect($data)->only([
        'prompt', 'stem', 'default_points', 'correct_payload', 'metadata',
        'difficulty', 'status', 'explanation',
      ])->all();
      if (isset($data['question_type'])) {
        $payload['question_type'] = QuestionType::from($data['question_type']);
      }
      $payload['updated_by_user_id'] = $actor?->id;
      $question->fill($payload)->save();
      if (array_key_exists('options', $data)) {
        $question->options()->delete();
        $this->syncOptions($question, $data['options'] ?? []);
      }

      return $question->fresh('options');
    });
  }

  /** @param  list<array<string, mixed>>  $options */
  private function syncOptions(Question $question, array $options): void
  {
    foreach ($options as $index => $option) {
      QuestionOption::query()->create([
        'question_id' => $question->id,
        'label' => $option['label'] ?? chr(65 + $index),
        'body' => $option['body'] ?? null,
        'match_key' => $option['match_key'] ?? null,
        'is_correct' => (bool) ($option['is_correct'] ?? false),
        'sort_order' => $option['sort_order'] ?? $index,
      ]);
    }
  }

  /** @param  array<string, mixed>  $filters */
  public function paginateAssessments(array $filters = []): LengthAwarePaginator
  {
    $query = Assessment::query()->with(['course:id,uuid,title', 'lesson:id,uuid,title'])->withCount('questions')->latest();
    if (! empty($filters['assessment_type'])) {
      $query->where('assessment_type', $filters['assessment_type']);
    }
    if (! empty($filters['status'])) {
      $query->where('status', $filters['status']);
    }

    return $query->paginate(min(100, max(1, (int) ($filters['per_page'] ?? 25))));
  }

  /** @param  array<string, mixed>  $data */
  public function createAssessment(array $data, ?User $actor = null): Assessment
  {
    return DB::transaction(function () use ($data, $actor): Assessment {
      $courseId = ! empty($data['course_id'])
        ? Course::query()->where('uuid', $data['course_id'])->value('id')
        : null;
      $moduleId = ! empty($data['module_id'])
        ? \App\Modules\Lms\Models\CourseModule::query()->where('uuid', $data['module_id'])->value('id')
        : null;
      $lessonId = ! empty($data['lesson_id'])
        ? Lesson::query()->where('uuid', $data['lesson_id'])->value('id')
        : null;
      $title = $data['title'];
      $assessment = Assessment::query()->create([
        'course_id' => $courseId,
        'module_id' => $moduleId,
        'lesson_id' => $lessonId,
        'title' => $title,
        'slug' => $data['slug'] ?? Str::slug($title).'-'.Str::lower(Str::random(4)),
        'description' => $data['description'] ?? null,
        'assessment_type' => AssessmentType::from($data['assessment_type'] ?? 'quiz'),
        'status' => AssessmentStatus::from($data['status'] ?? 'draft'),
        'pass_mark' => $data['pass_mark'] ?? 70,
        'time_limit_seconds' => $data['time_limit_seconds'] ?? null,
        'max_attempts' => $data['max_attempts'] ?? null,
        'retake_cooldown_minutes' => $data['retake_cooldown_minutes'] ?? null,
        'randomize_questions' => (bool) ($data['randomize_questions'] ?? false),
        'random_question_count' => $data['random_question_count'] ?? null,
        'negative_marking' => (bool) ($data['negative_marking'] ?? false),
        'negative_mark_value' => $data['negative_mark_value'] ?? 0,
        'show_immediate_result' => (bool) ($data['show_immediate_result'] ?? true),
        'allow_review' => (bool) ($data['allow_review'] ?? true),
        'requires_instructor_grading' => (bool) ($data['requires_instructor_grading'] ?? false),
        'settings' => $data['settings'] ?? null,
        'created_by_user_id' => $actor?->id,
        'updated_by_user_id' => $actor?->id,
      ]);

      $this->syncQuestions($assessment, $data['question_ids'] ?? []);

      return $assessment->load(['questions.options', 'course', 'lesson']);
    });
  }

  /** @param  array<string, mixed>  $data */
  public function updateAssessment(Assessment $assessment, array $data, ?User $actor = null): Assessment
  {
    return DB::transaction(function () use ($assessment, $data, $actor): Assessment {
      if (isset($data['course_id'])) {
        $data['course_id'] = $data['course_id']
          ? Course::query()->where('uuid', $data['course_id'])->value('id')
          : null;
      }
      if (isset($data['lesson_id'])) {
        $data['lesson_id'] = $data['lesson_id']
          ? Lesson::query()->where('uuid', $data['lesson_id'])->value('id')
          : null;
      }
      if (isset($data['assessment_type'])) {
        $data['assessment_type'] = AssessmentType::from($data['assessment_type']);
      }
      if (isset($data['status'])) {
        $data['status'] = AssessmentStatus::from($data['status']);
      }
      $data['updated_by_user_id'] = $actor?->id;
      $assessment->fill(collect($data)->except(['question_ids'])->all())->save();
      if (array_key_exists('question_ids', $data)) {
        $this->syncQuestions($assessment, $data['question_ids'] ?? []);
      }

      return $assessment->fresh(['questions.options', 'course', 'lesson']);
    });
  }

  /** @param  list<string|array{id: string, points?: float|null, sort_order?: int}>  $questionIds */
  private function syncQuestions(Assessment $assessment, array $questionIds): void
  {
    $sync = [];
    foreach ($questionIds as $index => $row) {
      $id = is_array($row) ? ($row['id'] ?? null) : $row;
      $question = Question::query()->where('uuid', $id)->first();
      if (! $question) {
        continue;
      }
      $sync[$question->id] = [
        'points' => is_array($row) ? ($row['points'] ?? null) : null,
        'sort_order' => is_array($row) ? ($row['sort_order'] ?? $index) : $index,
      ];
    }
    $assessment->questions()->sync($sync);
  }
}
