<?php

declare(strict_types=1);

namespace Tests\Feature\Lms;

use App\Modules\Lms\Enums\AssessmentStatus;
use App\Modules\Lms\Enums\AssessmentType;
use App\Modules\Lms\Enums\QuestionType;
use App\Modules\Lms\Models\Assessment;
use App\Modules\Lms\Models\Question;
use Tests\Feature\Iam\IamTestCase;

final class LmsAssessmentEngineTest extends IamTestCase
{
  public function test_question_bank_quiz_auto_grade_and_transcript(): void
  {
    $mcq = $this->postJson('/api/v1/lms/questions', [
      'prompt' => 'What is 2+2?',
      'question_type' => 'multiple_choice',
      'default_points' => 2,
      'options' => [
        ['label' => 'A', 'body' => '3', 'is_correct' => false],
        ['label' => 'B', 'body' => '4', 'is_correct' => true],
        ['label' => 'C', 'body' => '5', 'is_correct' => false],
      ],
    ])->assertCreated()->json('data.question.id');

    $tf = $this->postJson('/api/v1/lms/questions', [
      'prompt' => 'The sky is blue.',
      'question_type' => 'true_false',
      'default_points' => 1,
      'options' => [
        ['label' => 'T', 'body' => 'True', 'is_correct' => true],
        ['label' => 'F', 'body' => 'False', 'is_correct' => false],
      ],
    ])->assertCreated()->json('data.question');

    $fill = $this->postJson('/api/v1/lms/questions', [
      'prompt' => 'Capital of France?',
      'question_type' => 'fill_blank',
      'default_points' => 1,
      'correct_payload' => ['accepted_answers' => ['Paris', 'paris']],
    ])->assertCreated()->json('data.question.id');

    $essay = $this->postJson('/api/v1/lms/questions', [
      'prompt' => 'Explain marketplace ministry.',
      'question_type' => 'essay',
      'default_points' => 5,
    ])->assertCreated()->json('data.question.id');

    $assessmentId = $this->postJson('/api/v1/lms/assessments', [
      'title' => 'Foundations Quiz',
      'assessment_type' => 'quiz',
      'status' => 'published',
      'pass_mark' => 50,
      'show_immediate_result' => true,
      'allow_review' => true,
      'max_attempts' => 3,
      'question_ids' => [$mcq, $tf['id'], $fill],
    ])->assertCreated()->json('data.assessment.id');

    $learner = $this->memberUser();

    $take = $this->actingAs($learner)
      ->postJson("/api/v1/learner/assessments/{$assessmentId}/start")
      ->assertCreated()
      ->json('data');

    $attemptId = $take['attempt']['id'];
    $questions = collect($take['questions']);

    $mcqOption = Question::query()->where('uuid', $mcq)->with('options')->firstOrFail()
      ->options->firstWhere('is_correct', true)->uuid;
    $tfOption = Question::query()->where('uuid', $tf['id'])->with('options')->firstOrFail()
      ->options->firstWhere('is_correct', true)->uuid;

    $this->actingAs($learner)
      ->postJson("/api/v1/learner/attempts/{$attemptId}/submit", [
        'answers' => [
          ['question_id' => $mcq, 'response' => ['selected_option_id' => $mcqOption]],
          ['question_id' => $tf['id'], 'response' => ['selected_option_id' => $tfOption]],
          ['question_id' => $fill, 'response' => ['text' => 'Paris']],
        ],
      ])
      ->assertOk()
      ->assertJsonPath('data.result.passed', true)
      ->assertJsonPath('data.result.grade', 'A');

    $this->actingAs($learner)
      ->getJson('/api/v1/learner/transcript')
      ->assertOk()
      ->assertJsonPath('data.assessments.0.passed', true);

    // Essay assessment requires instructor grading
    $this->actingAs($this->admin);
    $essayAssessment = $this->postJson('/api/v1/lms/assessments', [
      'title' => 'Essay Assignment',
      'assessment_type' => 'assignment',
      'status' => 'published',
      'pass_mark' => 60,
      'requires_instructor_grading' => true,
      'question_ids' => [$essay],
    ])->assertCreated()->json('data.assessment.id');

    $essayTake = $this->actingAs($learner)
      ->postJson("/api/v1/learner/assessments/{$essayAssessment}/start")
      ->assertCreated()
      ->json('data');

    $essayAttempt = $essayTake['attempt']['id'];
    $this->actingAs($learner)
      ->postJson("/api/v1/learner/attempts/{$essayAttempt}/submit", [
        'answers' => [
          ['question_id' => $essay, 'response' => ['text' => 'Ministry in the marketplace...']],
        ],
      ])
      ->assertOk()
      ->assertJsonPath('data.result.status', 'grading');

    $this->actingAs($this->admin);
    $queue = $this->getJson('/api/v1/lms/grading-queue')->assertOk()->json('data.data');
    $this->assertNotEmpty($queue);

    $attemptModel = \App\Modules\Lms\Models\AssessmentAttempt::query()->where('uuid', $essayAttempt)->with('answers')->firstOrFail();
    $answerUuid = $attemptModel->answers->first()->uuid;

    $this->postJson("/api/v1/lms/attempts/{$essayAttempt}/grade", [
      'grades' => [
        ['answer_id' => $answerUuid, 'points_awarded' => 5, 'feedback' => 'Strong reflection'],
      ],
      'remarks' => 'Excellent essay.',
    ])->assertOk()
      ->assertJsonPath('data.result.passed', true)
      ->assertJsonPath('data.result.grade', 'A');
  }

  public function test_timed_test_and_negative_marking_config_persist(): void
  {
    $q = Question::query()->create([
      'prompt' => 'TF',
      'question_type' => QuestionType::TrueFalse,
      'default_points' => 1,
      'status' => 'active',
    ]);
    $q->options()->create(['label' => 'T', 'body' => 'True', 'is_correct' => true, 'sort_order' => 0]);
    $q->options()->create(['label' => 'F', 'body' => 'False', 'is_correct' => false, 'sort_order' => 1]);

    $id = $this->postJson('/api/v1/lms/assessments', [
      'title' => 'Timed Exam',
      'assessment_type' => 'examination',
      'status' => 'published',
      'time_limit_seconds' => 600,
      'negative_marking' => true,
      'negative_mark_value' => 0.25,
      'randomize_questions' => true,
      'random_question_count' => 1,
      'max_attempts' => 1,
      'retake_cooldown_minutes' => 60,
      'pass_mark' => 70,
      'question_ids' => [$q->uuid],
    ])->assertCreated()->json('data.assessment');

    $this->assertSame('examination', $id['assessment_type']);
    $this->assertTrue($id['negative_marking']);
    $this->assertSame(600, $id['time_limit_seconds']);
  }
}
