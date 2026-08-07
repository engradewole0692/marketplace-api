<?php

declare(strict_types=1);

namespace App\Modules\Lms\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\ApiController;
use App\Modules\Lms\Models\Course;
use App\Modules\Lms\Services\LmsReportExportService;
use App\Modules\Lms\Services\LmsReportingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class LmsReportsAdminController extends ApiController
{
  public function dashboard(Request $request, LmsReportingService $reporting): JsonResponse
  {
    $this->authorize('viewAny', Course::class);

    return $this->responder->success(
      data: $reporting->dashboard($request->query()),
      message: 'LMS dashboard analytics retrieved.',
    );
  }

  public function show(Request $request, string $type, LmsReportingService $reporting): JsonResponse
  {
    $this->authorize('viewAny', Course::class);

    $report = $reporting->report($type, $request->query());

    return $this->responder->success(
      data: $report,
      message: ucfirst($type).' report retrieved.',
    );
  }

  public function export(
    Request $request,
    string $type,
    LmsReportingService $reporting,
    LmsReportExportService $exports,
  ): JsonResponse|StreamedResponse {
    $this->authorize('viewAny', Course::class);

    $validated = $request->validate([
      'format' => ['required', 'string', Rule::in(LmsReportExportService::FORMATS)],
      'from' => ['nullable', 'date'],
      'to' => ['nullable', 'date'],
      'status' => ['nullable', 'string', 'max:40'],
      'download' => ['sometimes', 'boolean'],
    ]);

    $report = $reporting->report($type, $request->query());
    $file = $exports->export($report, $validated['format']);

    if ($request->boolean('download')) {
      $absolute = storage_path('app/public/'.$file['path']);

      return response()->streamDownload(function () use ($absolute): void {
        echo file_get_contents($absolute) ?: '';
      }, $file['filename'], [
        'Content-Type' => $file['mime'],
      ]);
    }

    return $this->responder->success(
      data: [
        'export' => $file,
        'summary' => $report['summary'],
        'row_count' => count($report['rows']),
      ],
      message: 'Export generated.',
    );
  }
}
