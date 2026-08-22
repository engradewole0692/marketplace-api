<?php

declare(strict_types=1);

namespace App\Modules\Lms\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\ApiController;
use App\Modules\Lms\Http\Resources\LmsCourseImportResource;
use App\Modules\Lms\Models\Course;
use App\Modules\Lms\Models\LmsCourseImport;
use App\Modules\Lms\Services\LmsCourseImportService;
use App\Support\Api\PaginatedResponseBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class CourseImportAdminController extends ApiController
{
  public function schema(LmsCourseImportService $import): JsonResponse
  {
    $this->authorize('create', Course::class);

    return $this->responder->success(
      data: ['importer' => $import->schema()],
      message: 'Course import schema retrieved.',
    );
  }

  public function template(LmsCourseImportService $import): mixed
  {
    $this->authorize('create', Course::class);

    return $import->downloadTemplate();
  }

  public function dryRun(Request $request, LmsCourseImportService $import): JsonResponse
  {
    $this->authorize('create', Course::class);
    $request->validate([
      'file' => ['required', 'file', 'mimes:xlsx,xls', 'max:10240'],
      'create_missing_schools' => ['sometimes', 'boolean'],
      'create_missing_categories' => ['sometimes', 'boolean'],
      'create_missing_program_modules' => ['sometimes', 'boolean'],
      'publish_after_import' => ['sometimes', 'boolean'],
      'only_free_courses' => ['sometimes', 'boolean'],
      'only_access_types' => ['sometimes', 'array'],
      'only_access_types.*' => ['string', 'in:free,school,standalone'],
    ]);

    try {
      $report = $import->importFromUpload(
        $request->file('file'),
        $this->settingsFromRequest($request),
        true,
        $request->user(),
      );
    } catch (\InvalidArgumentException $e) {
      return $this->responder->error(
        message: $e->getMessage(),
        code: 'validation_error',
        status: 422,
      );
    }

    return $this->responder->success(
      data: ['dry_run' => $report],
      message: 'Course import dry-run completed.',
    );
  }

  public function run(Request $request, LmsCourseImportService $import): JsonResponse
  {
    $this->authorize('create', Course::class);
    $request->validate([
      'file' => ['required', 'file', 'mimes:xlsx,xls', 'max:10240'],
      'create_missing_schools' => ['sometimes', 'boolean'],
      'create_missing_categories' => ['sometimes', 'boolean'],
      'create_missing_program_modules' => ['sometimes', 'boolean'],
      'publish_after_import' => ['sometimes', 'boolean'],
      'only_free_courses' => ['sometimes', 'boolean'],
      'only_access_types' => ['sometimes', 'array'],
      'only_access_types.*' => ['string', 'in:free,school,standalone'],
    ]);

    $settings = $this->settingsFromRequest($request);
    $record = LmsCourseImport::query()->create([
      'admin_user_id' => $request->user()->id,
      'filename' => $request->file('file')->getClientOriginalName(),
      'status' => 'processing',
      'publish_after_import' => $settings['publish_after_import'],
      'create_missing_schools' => $settings['create_missing_schools'],
      'create_missing_categories' => $settings['create_missing_categories'],
      'create_missing_program_modules' => $settings['create_missing_program_modules'],
      'settings' => $settings,
    ]);

    try {
      $report = $import->importFromUpload(
        $request->file('file'),
        $settings,
        false,
        $request->user(),
        $record,
      );
    } catch (\InvalidArgumentException $e) {
      $record->update(['status' => 'failed', 'report' => ['error' => $e->getMessage()]]);

      return $this->responder->error(
        message: $e->getMessage(),
        code: 'validation_error',
        status: 422,
      );
    } catch (\Throwable $e) {
      $record->update(['status' => 'failed', 'report' => ['error' => $e->getMessage()]]);
      throw $e;
    }

    return $this->responder->success(
      data: [
        'import' => $report,
        'history' => new LmsCourseImportResource($record->fresh(['administrator'])),
      ],
      message: 'Course import completed.',
    );
  }

  public function index(Request $request): JsonResponse
  {
    $this->authorize('create', Course::class);

    $paginator = LmsCourseImport::query()
      ->with(['administrator'])
      ->orderByDesc('created_at')
      ->paginate(min(100, max(1, (int) $request->query('per_page', 25))));

    return $this->responder->success(
      data: PaginatedResponseBuilder::fromPaginator($paginator, LmsCourseImportResource::class),
      message: 'Course import history retrieved.',
    );
  }

  public function show(LmsCourseImport $courseImport): JsonResponse
  {
    $this->authorize('create', Course::class);
    $courseImport->load(['administrator']);

    return $this->responder->success(
      data: ['import' => new LmsCourseImportResource($courseImport)],
      message: 'Course import details retrieved.',
    );
  }

  /** @return array<string, mixed> */
  private function settingsFromRequest(Request $request): array
  {
    $settings = [
      'create_missing_schools' => $request->boolean('create_missing_schools'),
      'create_missing_categories' => $request->boolean('create_missing_categories'),
      'create_missing_program_modules' => $request->boolean('create_missing_program_modules'),
      'publish_after_import' => $request->boolean('publish_after_import'),
    ];

    if ($request->boolean('only_free_courses')) {
      $settings['only_access_types'] = ['free'];
    }

    $only = $request->input('only_access_types');
    if (is_array($only) && $only !== []) {
      $settings['only_access_types'] = array_values(array_filter(array_map('strval', $only)));
    } elseif (is_string($only) && $only !== '') {
      $settings['only_access_types'] = [$only];
    }

    return $settings;
  }
}
