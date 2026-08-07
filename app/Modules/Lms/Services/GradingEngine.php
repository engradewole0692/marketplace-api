<?php

declare(strict_types=1);

namespace App\Modules\Lms\Services;

use App\Contracts\ServiceContract;
use App\Modules\Lms\Enums\QuestionType;
use App\Modules\Lms\Models\Assessment;
use App\Modules\Lms\Models\AttemptAnswer;
use App\Modules\Lms\Models\Question;

final class GradingEngine implements ServiceContract
{
  /**
   * @param  array<string, mixed>|null  $response
   * @return array{is_correct: bool|null, points: float, needs_manual: bool}
   */
  public function gradeQuestion(Question $question, ?array $response, float $pointsPossible, Assessment $assessment): array
  {
    $type = $question->question_type instanceof QuestionType
      ? $question->question_type
      : QuestionType::tryFrom((string) $question->question_type);

    if ($type === QuestionType::Essay) {
      return ['is_correct' => null, 'points' => 0.0, 'needs_manual' => true];
    }

    $correct = $this->isCorrect($question, $type, $response ?? []);
    $points = 0.0;
    if ($correct === true) {
      $points = $pointsPossible;
    } elseif ($correct === false && $assessment->negative_marking) {
      $points = -1 * abs((float) $assessment->negative_mark_value);
    }

    return [
      'is_correct' => $correct,
      'points' => round($points, 2),
      'needs_manual' => false,
    ];
  }

  /**
   * @param  array<string, mixed>  $response
   */
  private function isCorrect(Question $question, ?QuestionType $type, array $response): ?bool
  {
    $payload = $question->correct_payload ?? [];
    $options = $question->relationLoaded('options') ? $question->options : $question->options()->get();

    return match ($type) {
      QuestionType::MultipleChoice, QuestionType::TrueFalse => $this->singleChoiceCorrect($options, $response),
      QuestionType::Checkbox => $this->multiChoiceCorrect($options, $response),
      QuestionType::FillBlank => $this->fillBlankCorrect($payload, $response),
      QuestionType::Matching => $this->matchingCorrect($options, $response),
      QuestionType::Ordering => $this->orderingCorrect($options, $payload, $response),
      default => null,
    };
  }

  /** @param  iterable<int, mixed>  $options */
  private function singleChoiceCorrect(iterable $options, array $response): bool
  {
    $selected = (string) ($response['selected_option_id'] ?? $response['value'] ?? '');
    foreach ($options as $option) {
      if ($option->is_correct && ($option->uuid === $selected || (string) $option->id === $selected)) {
        return true;
      }
    }

    return false;
  }

  /** @param  iterable<int, mixed>  $options */
  private function multiChoiceCorrect(iterable $options, array $response): bool
  {
    $selected = collect($response['selected_option_ids'] ?? $response['values'] ?? [])
      ->map(fn ($v) => (string) $v)
      ->sort()
      ->values()
      ->all();
    $correct = collect($options)
      ->filter(fn ($o) => $o->is_correct)
      ->map(fn ($o) => $o->uuid)
      ->sort()
      ->values()
      ->all();

    return $selected === $correct && $correct !== [];
  }

  /** @param  array<string, mixed>  $payload */
  private function fillBlankCorrect(array $payload, array $response): bool
  {
    $answers = collect($payload['accepted_answers'] ?? $payload['answers'] ?? [])
      ->map(fn ($a) => mb_strtolower(trim((string) $a)))
      ->filter()
      ->all();
    $given = mb_strtolower(trim((string) ($response['text'] ?? $response['value'] ?? '')));

    return $given !== '' && in_array($given, $answers, true);
  }

  /** @param  iterable<int, mixed>  $options */
  private function matchingCorrect(iterable $options, array $response): bool
  {
    $pairs = $response['pairs'] ?? [];
    if (! is_array($pairs) || $pairs === []) {
      return false;
    }

    $expected = [];
    foreach ($options as $option) {
      if ($option->match_key) {
        $expected[$option->uuid] = (string) $option->match_key;
      }
    }

    foreach ($expected as $left => $right) {
      if (($pairs[$left] ?? null) !== $right && ($pairs[$right] ?? null) !== $left) {
        // Accept either direction keyed by option uuid -> match_key
        if (($pairs[$left] ?? null) !== $right) {
          return false;
        }
      }
    }

    return count($expected) > 0;
  }

  /**
   * @param  iterable<int, mixed>  $options
   * @param  array<string, mixed>  $payload
   */
  private function orderingCorrect(iterable $options, array $payload, array $response): bool
  {
    $order = collect($response['order'] ?? [])->map(fn ($v) => (string) $v)->values()->all();
    $expected = $payload['order'] ?? null;
    if (is_array($expected) && $expected !== []) {
      $expected = collect($expected)->map(fn ($v) => (string) $v)->values()->all();

      return $order === $expected;
    }

    $expected = collect($options)->sortBy('sort_order')->map(fn ($o) => $o->uuid)->values()->all();

    return $order === $expected;
  }

  public function letterGrade(float $percentage): string
  {
    return match (true) {
      $percentage >= 90 => 'A',
      $percentage >= 80 => 'B',
      $percentage >= 70 => 'C',
      $percentage >= 60 => 'D',
      default => 'F',
    };
  }

  public function remarks(bool $passed, float $percentage): string
  {
    if ($passed) {
      return $percentage >= 90
        ? 'Excellent performance. Outstanding mastery of the material.'
        : 'Passed. Continue building on this foundation.';
    }

    return 'Did not meet the pass mark. Review the material and retake when eligible.';
  }
}
