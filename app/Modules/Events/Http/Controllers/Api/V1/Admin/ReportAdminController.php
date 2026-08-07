<?php

declare(strict_types=1);

namespace App\Modules\Events\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\ApiController;
use App\Modules\Events\Http\Requests\ReportRequest;
use App\Modules\Events\Http\Resources\EventReportSnapshotResource;
use App\Modules\Events\Models\EventReportSnapshot;
use App\Modules\Events\Services\ReportService;
use App\Support\Api\PaginatedResponseBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ReportAdminController extends ApiController
{
  public function index(Request $request, ReportService $service): JsonResponse
  {
    $this->authorize('viewAny', EventReportSnapshot::class);

    return $this->responder->success(
      data: PaginatedResponseBuilder::fromPaginator($service->paginate($request->query()), EventReportSnapshotResource::class),
      message: 'Report snapshots retrieved.',
    );
  }

  public function generate(ReportRequest $request, ReportService $service): JsonResponse
  {
    $this->authorize('create', EventReportSnapshot::class);

    $snapshot = $service->generate($request->validated(), $request->user());

    return $this->responder->success(
      data: ['report' => new EventReportSnapshotResource($snapshot)],
      message: 'Report generated.',
      status: 201,
    );
  }

  public function download(EventReportSnapshot $snapshot, ReportService $service): StreamedResponse
  {
    $this->authorize('view', $snapshot);

    return $service->download($snapshot);
  }
}
