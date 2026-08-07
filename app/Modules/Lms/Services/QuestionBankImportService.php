<?php

declare(strict_types=1);

namespace App\Modules\Lms\Services;

use App\Contracts\ServiceContract;
use App\Models\User;
use App\Modules\Cms\Models\CmsMinistry;
use App\Modules\Lms\Models\Course;
use App\Modules\Lms\Models\Question;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Question Bank bulk import — extends existing lms_questions / options (no new tables).
 */
final class QuestionBankImportService implements ServiceContract
{
  public const TEMPLATE_HEADERS = [
    'Question',
    'Option A',
    'Option B',
    'Option C',
    'Option D',
    'Option E',
    'Correct Option',
    'Marks',
    'Difficulty',
    'Question Type',
    'Explanation',
    'Topic',
    'Course',
    'Ministry',
    'Level',
    'Tags',
  ];

  public function __construct(
    private readonly AssessmentAdminService $questions,
  ) {}

  /**
   * @return array{
   *   dry_run: bool,
   *   summary: array{total_rows: int, valid: int, created: int, skipped: int, failed: int},
   *   rows: list<array{row: int, status: string, message: string, prompt?: string}>
   * }
   */
  public function import(UploadedFile $file, bool $dryRun = true, ?User $actor = null): array
  {
    $rows = $this->readRows($file);
    $report = [];
    $created = 0;
    $skipped = 0;
    $failed = 0;
    $valid = 0;
    $seenNormalized = [];

    $existingPrompts = Question::query()
      ->pluck('prompt')
      ->map(fn (string $p) => $this->normalizePrompt($p))
      ->flip()
      ->all();

    $importer = function () use (
      $rows,
      $dryRun,
      $actor,
      &$report,
      &$created,
      &$skipped,
      &$failed,
      &$valid,
      &$seenNormalized,
      $existingPrompts,
    ): void {
      foreach ($rows as $index => $raw) {
        $rowNumber = $index + 2; // header is row 1
        $parsed = $this->parseRow($raw, $rowNumber);

        if ($parsed['status'] !== 'valid') {
          $failed++;
          $report[] = [
            'row' => $rowNumber,
            'status' => $parsed['status'],
            'message' => $parsed['message'],
            'prompt' => $parsed['prompt'] ?? null,
          ];
          continue;
        }

        $normalized = $this->normalizePrompt((string) $parsed['prompt']);
        if (isset($existingPrompts[$normalized]) || isset($seenNormalized[$normalized])) {
          $skipped++;
          $report[] = [
            'row' => $rowNumber,
            'status' => 'duplicate',
            'message' => 'Duplicate question (same prompt already exists or appears earlier in this file).',
            'prompt' => $parsed['prompt'],
          ];
          continue;
        }

        $valid++;
        $seenNormalized[$normalized] = true;

        if ($dryRun) {
          $report[] = [
            'row' => $rowNumber,
            'status' => 'would_create',
            'message' => 'Row is valid and would be imported.',
            'prompt' => $parsed['prompt'],
          ];
          continue;
        }

        $this->questions->createQuestion($parsed['payload'], $actor);
        $existingPrompts[$normalized] = true;
        $created++;
        $report[] = [
          'row' => $rowNumber,
          'status' => 'created',
          'message' => 'Question created.',
          'prompt' => $parsed['prompt'],
        ];
      }
    };

    if ($dryRun) {
      $importer();
    } else {
      DB::transaction(function () use ($importer): void {
        $importer();
      });
    }

    return [
      'dry_run' => $dryRun,
      'summary' => [
        'total_rows' => count($rows),
        'valid' => $valid,
        'created' => $created,
        'skipped' => $skipped,
        'failed' => $failed,
      ],
      'rows' => $report,
    ];
  }

  public function downloadTemplate(string $format = 'csv'): StreamedResponse|\Illuminate\Http\Response
  {
    $format = strtolower($format) === 'xlsx' ? 'xlsx' : 'csv';

    if ($format === 'xlsx' && class_exists(Spreadsheet::class)) {
      $spreadsheet = new Spreadsheet;
      $sheet = $spreadsheet->getActiveSheet();
      $sheet->fromArray(self::TEMPLATE_HEADERS, null, 'A1');
      $sheet->fromArray($this->sampleDataRow(), null, 'A2');
      $writer = new Xlsx($spreadsheet);

      return response()->streamDownload(function () use ($writer): void {
        $writer->save('php://output');
      }, 'question-bank-template.xlsx', [
        'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
      ]);
    }

    $csv = fopen('php://temp', 'r+');
    fputcsv($csv, self::TEMPLATE_HEADERS);
    fputcsv($csv, $this->sampleDataRow());
    rewind($csv);
    $content = stream_get_contents($csv) ?: '';
    fclose($csv);

    return response($content, 200, [
      'Content-Type' => 'text/csv; charset=UTF-8',
      'Content-Disposition' => 'attachment; filename="question-bank-template.csv"',
    ]);
  }

  /**
   * @return list<array<string, string>>
   */
  private function readRows(UploadedFile $file): array
  {
    $ext = strtolower($file->getClientOriginalExtension() ?: pathinfo($file->getClientOriginalName(), PATHINFO_EXTENSION));

    if (in_array($ext, ['xlsx', 'xls'], true) && class_exists(IOFactory::class)) {
      $spreadsheet = IOFactory::load($file->getRealPath());
      $matrix = $spreadsheet->getActiveSheet()->toArray(null, true, true, false);
      if ($matrix === []) {
        return [];
      }
      $headers = array_map(fn ($h) => $this->normalizeHeader((string) $h), array_shift($matrix) ?? []);

      return $this->matrixToAssoc($headers, $matrix);
    }

    $handle = fopen($file->getRealPath(), 'r');
    if ($handle === false) {
      return [];
    }
    $headerLine = fgetcsv($handle);
    if ($headerLine === false) {
      fclose($handle);

      return [];
    }
    $headers = array_map(fn ($h) => $this->normalizeHeader((string) $h), $headerLine);
    $matrix = [];
    while (($line = fgetcsv($handle)) !== false) {
      $matrix[] = $line;
    }
    fclose($handle);

    return $this->matrixToAssoc($headers, $matrix);
  }

  /**
   * @param  list<string>  $headers
   * @param  list<list<mixed>>  $matrix
   * @return list<array<string, string>>
   */
  private function matrixToAssoc(array $headers, array $matrix): array
  {
    $rows = [];
    foreach ($matrix as $line) {
      if ($this->rowIsEmpty($line)) {
        continue;
      }
      $assoc = [];
      foreach ($headers as $i => $key) {
        if ($key === '') {
          continue;
        }
        $assoc[$key] = trim((string) ($line[$i] ?? ''));
      }
      $rows[] = $assoc;
    }

    return $rows;
  }

  /** @param  list<mixed>  $line */
  private function rowIsEmpty(array $line): bool
  {
    foreach ($line as $cell) {
      if (trim((string) $cell) !== '') {
        return false;
      }
    }

    return true;
  }

  /**
   * @param  array<string, string>  $raw
   * @return array{status: string, message: string, prompt?: string, payload?: array<string, mixed>}
   */
  private function parseRow(array $raw, int $rowNumber): array
  {
    $prompt = $raw['question'] ?? '';
    if ($prompt === '') {
      return ['status' => 'failed', 'message' => 'Missing question text.', 'prompt' => ''];
    }

    $typeRaw = strtolower(trim($raw['question_type'] ?? 'multiple_choice'));
    $typeRaw = match ($typeRaw) {
      'objective', 'mcq', 'multiple choice', 'multiple_choice' => 'multiple_choice',
      'essay', 'written' => 'essay',
      'true_false', 'true/false', 'true false' => 'true_false',
      default => $typeRaw,
    };

    if (! in_array($typeRaw, ['multiple_choice', 'essay', 'true_false', 'checkbox'], true)) {
      return [
        'status' => 'failed',
        'message' => "Invalid question type '{$raw['question_type']}'. Use multiple_choice or essay.",
        'prompt' => $prompt,
      ];
    }

    $marks = $raw['marks'] !== '' ? (float) $raw['marks'] : 1.0;
    if ($marks < 0) {
      return ['status' => 'failed', 'message' => 'Marks cannot be negative.', 'prompt' => $prompt];
    }

    $courseRef = $raw['course'] ?? '';
    $ministryRef = $raw['ministry'] ?? '';
    $courseId = null;
    $ministryId = null;

    if ($courseRef !== '') {
      $course = Course::query()
        ->where(function ($q) use ($courseRef): void {
          $q->where('uuid', $courseRef)
            ->orWhere('slug', $courseRef)
            ->orWhere('slug', Str::slug($courseRef))
            ->orWhere('title', $courseRef);
        })
        ->first();
      if (! $course) {
        return [
          'status' => 'failed',
          'message' => "Missing course: '{$courseRef}' was not found.",
          'prompt' => $prompt,
        ];
      }
      $courseId = $course->uuid;
    }

    if ($ministryRef !== '') {
      $ministry = CmsMinistry::query()
        ->where(function ($q) use ($ministryRef): void {
          $q->where('uuid', $ministryRef)
            ->orWhere('slug', $ministryRef)
            ->orWhere('slug', Str::slug($ministryRef))
            ->orWhere('name', $ministryRef);
        })
        ->first();
      if (! $ministry) {
        return [
          'status' => 'failed',
          'message' => "Invalid ministry: '{$ministryRef}' was not found.",
          'prompt' => $prompt,
        ];
      }
      $ministryId = $ministry->uuid;
    }

    $metadata = array_filter([
      'topic' => $raw['topic'] !== '' ? $raw['topic'] : null,
      'level' => $raw['level'] !== '' ? $raw['level'] : null,
      'tags' => $raw['tags'] !== ''
        ? array_values(array_filter(array_map('trim', explode(',', $raw['tags']))))
        : null,
      'course_id' => $courseId,
      'course_ref' => $courseRef !== '' ? $courseRef : null,
      'ministry_id' => $ministryId,
      'ministry_ref' => $ministryRef !== '' ? $ministryRef : null,
    ], fn ($v) => $v !== null && $v !== []);

    $payload = [
      'prompt' => $prompt,
      'question_type' => $typeRaw,
      'default_points' => $marks,
      'difficulty' => $raw['difficulty'] !== '' ? $raw['difficulty'] : null,
      'explanation' => $raw['explanation'] !== '' ? $raw['explanation'] : null,
      'status' => 'active',
      'metadata' => $metadata === [] ? null : $metadata,
    ];

    if ($typeRaw === 'essay') {
      return [
        'status' => 'valid',
        'message' => 'OK',
        'prompt' => $prompt,
        'payload' => $payload,
      ];
    }

    $optionBodies = [];
    foreach (['a', 'b', 'c', 'd', 'e'] as $letter) {
      $key = 'option_'.$letter;
      $body = $raw[$key] ?? '';
      if ($body !== '') {
        $optionBodies[$letter] = $body;
      }
    }

    if (count($optionBodies) < 3 && $typeRaw === 'multiple_choice') {
      return [
        'status' => 'failed',
        'message' => 'Objective questions require at least 3 options (A–C minimum).',
        'prompt' => $prompt,
      ];
    }

    if ($optionBodies === [] && $typeRaw === 'true_false') {
      $optionBodies = ['a' => 'True', 'b' => 'False'];
    }

    if ($optionBodies === []) {
      return [
        'status' => 'failed',
        'message' => 'Missing answers / options.',
        'prompt' => $prompt,
      ];
    }

    $correct = strtolower(trim($raw['correct_option'] ?? ''));
    $correct = preg_replace('/^option\s+/i', '', $correct) ?? $correct;
    $correct = substr($correct, 0, 1);

    if ($correct === '' || ! isset($optionBodies[$correct])) {
      return [
        'status' => 'failed',
        'message' => "Invalid option: correct answer '{$raw['correct_option']}' does not match a provided option.",
        'prompt' => $prompt,
      ];
    }

    $options = [];
    $sort = 0;
    foreach ($optionBodies as $letter => $body) {
      $options[] = [
        'label' => strtoupper($letter),
        'body' => $body,
        'is_correct' => $letter === $correct,
        'sort_order' => $sort++,
      ];
    }

    $payload['options'] = $options;

    return [
      'status' => 'valid',
      'message' => 'OK',
      'prompt' => $prompt,
      'payload' => $payload,
    ];
  }

  private function normalizeHeader(string $header): string
  {
    $h = strtolower(trim($header));
    $h = str_replace(['-', ' '], '_', $h);

    return match ($h) {
      'question', 'prompt', 'question_text' => 'question',
      'option_a', 'a' => 'option_a',
      'option_b', 'b' => 'option_b',
      'option_c', 'c' => 'option_c',
      'option_d', 'd' => 'option_d',
      'option_e', 'e' => 'option_e',
      'correct_option', 'correct', 'answer' => 'correct_option',
      'marks', 'points', 'score' => 'marks',
      'difficulty' => 'difficulty',
      'question_type', 'type' => 'question_type',
      'explanation' => 'explanation',
      'topic' => 'topic',
      'course' => 'course',
      'ministry' => 'ministry',
      'level' => 'level',
      'tags', 'tag' => 'tags',
      default => $h,
    };
  }

  private function normalizePrompt(string $prompt): string
  {
    return Str::lower(preg_replace('/\s+/', ' ', trim($prompt)) ?? '');
  }

  /** @return list<string> */
  private function sampleDataRow(): array
  {
    return [
      'What is the primary calling of Marketplace Ministers?',
      'Kingdom influence in the workplace',
      'Only Sunday worship',
      'Political lobbying only',
      'Retail franchise ownership',
      '',
      'A',
      '1',
      'easy',
      'multiple_choice',
      'Believers are called to represent Christ in the marketplace.',
      'Calling',
      '',
      '',
      'Foundations',
      'identity,marketplace',
    ];
  }
}
