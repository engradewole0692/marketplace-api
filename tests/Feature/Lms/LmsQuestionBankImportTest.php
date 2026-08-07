<?php

declare(strict_types=1);

namespace Tests\Feature\Lms;

use App\Modules\Lms\Models\Question;
use Illuminate\Http\UploadedFile;
use Tests\Feature\Iam\IamTestCase;

final class LmsQuestionBankImportTest extends IamTestCase
{
  public function test_download_question_bank_csv_template(): void
  {
    $this->get('/api/v1/lms/questions/import/template?format=csv')
      ->assertOk()
      ->assertHeader('content-type', 'text/csv; charset=UTF-8');
  }

  public function test_dry_run_validates_and_does_not_persist(): void
  {
    $csv = $this->buildCsv([
      ['What is faith?', 'Belief', 'Hope only', 'Works only', '', '', 'A', '1', 'easy', 'multiple_choice', 'Trust', 'Faith', '', '', 'Foundations', 'faith'],
      ['Explain grace.', '', '', '', '', '', '', '5', 'medium', 'essay', '', 'Doctrine', '', '', '', 'grace'],
    ]);

    $before = Question::query()->count();

    $this->post('/api/v1/lms/questions/import', [
      'file' => $csv,
      'dry_run' => true,
    ], ['Accept' => 'application/json'])
      ->assertOk()
      ->assertJsonPath('data.dry_run', true)
      ->assertJsonPath('data.summary.valid', 2)
      ->assertJsonPath('data.summary.created', 0)
      ->assertJsonPath('data.summary.failed', 0);

    $this->assertSame($before, Question::query()->count());
  }

  public function test_import_creates_questions_and_rejects_duplicates_and_bad_rows(): void
  {
    $csv = $this->buildCsv([
      ['Capital of Nigeria?', 'Lagos', 'Abuja', 'Kano', 'Ibadan', '', 'B', '2', 'easy', 'multiple_choice', 'Abuja is capital', 'Geography', '', '', '', 'nigeria'],
      ['Capital of Nigeria?', 'Lagos', 'Abuja', 'Kano', '', '', 'B', '2', 'easy', 'multiple_choice', '', '', '', '', '', ''], // duplicate
      ['Broken MCQ', 'Only one', '', '', '', '', 'A', '1', 'easy', 'multiple_choice', '', '', '', '', '', ''], // <3 options
      ['Missing course q', 'A1', 'A2', 'A3', '', '', 'A', '1', 'easy', 'multiple_choice', '', '', 'not-a-real-course', '', '', ''],
      ['Invalid ministry q', 'A1', 'A2', 'A3', '', '', 'A', '1', 'easy', 'multiple_choice', '', '', '', 'not-a-real-ministry', '', ''],
      ['Essay reflection', '', '', '', '', '', '', '5', 'hard', 'essay', '', 'Leadership', '', '', 'L1', 'essay,reflection'],
    ]);

    $response = $this->post('/api/v1/lms/questions/import', [
      'file' => $csv,
      'dry_run' => false,
    ], ['Accept' => 'application/json'])
      ->assertOk();

    $response
      ->assertJsonPath('data.dry_run', false)
      ->assertJsonPath('data.summary.created', 2)
      ->assertJsonPath('data.summary.skipped', 1)
      ->assertJsonPath('data.summary.failed', 3);

    $this->assertDatabaseHas('lms_questions', [
      'prompt' => 'Capital of Nigeria?',
      'question_type' => 'multiple_choice',
    ]);
    $this->assertDatabaseHas('lms_questions', [
      'prompt' => 'Essay reflection',
      'question_type' => 'essay',
    ]);

    $mcq = Question::query()->where('prompt', 'Capital of Nigeria?')->with('options')->firstOrFail();
    $this->assertCount(4, $mcq->options);
    $this->assertSame(1, $mcq->options->where('is_correct', true)->count());
  }

  public function test_store_multiple_choice_requires_three_options(): void
  {
    $this->postJson('/api/v1/lms/questions', [
      'prompt' => 'Too few options?',
      'question_type' => 'multiple_choice',
      'options' => [
        ['label' => 'A', 'body' => 'One', 'is_correct' => true],
        ['label' => 'B', 'body' => 'Two', 'is_correct' => false],
      ],
    ])->assertStatus(422);
  }

  /**
   * @param  list<list<string>>  $rows
   */
  private function buildCsv(array $rows): UploadedFile
  {
    $headers = [
      'Question', 'Option A', 'Option B', 'Option C', 'Option D', 'Option E',
      'Correct Option', 'Marks', 'Difficulty', 'Question Type', 'Explanation',
      'Topic', 'Course', 'Ministry', 'Level', 'Tags',
    ];
    $fp = fopen('php://temp', 'r+');
    fputcsv($fp, $headers);
    foreach ($rows as $row) {
      fputcsv($fp, $row);
    }
    rewind($fp);
    $content = stream_get_contents($fp) ?: '';
    fclose($fp);

    return UploadedFile::fake()->createWithContent('questions.csv', $content);
  }
}
