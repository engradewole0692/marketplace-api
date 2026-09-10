<?php

declare(strict_types=1);

namespace App\Modules\Events\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\ApiController;
use App\Modules\Events\Http\Requests\ExportRequest;
use App\Modules\Events\Http\Resources\EventExportJobResource;
use App\Modules\Events\Models\EventExportJob;
use App\Modules\Events\Services\EventAuthorizationService;
use App\Modules\Events\Services\ExportService;
use App\Support\Api\PaginatedResponseBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ExportAdminController extends ApiController
{
  public function index(Request $request, ExportService $service): JsonResponse
  {
    $this->authorize('viewAny', EventExportJob::class);

    return $this->responder->success(
      data: PaginatedResponseBuilder::fromPaginator($service->paginate($request->query(), $request->user()), EventExportJobResource::class),
      message: 'Export jobs retrieved.',
    );
  }

  public function store(ExportRequest $request, ExportService $service): JsonResponse
  {
    $this->authorize('create', EventExportJob::class);
    $validated = $request->validated();
    app(EventAuthorizationService::class)->assertEventIdAccess(
      $request->user(),
      isset($validated['event_id']) ? (int) $validated['event_id'] : null,
    );

    $job = $service->queue($validated, $request->user());

    return $this->responder->success(
      data: ['export' => new EventExportJobResource($job)],
      message: 'Export job queued.',
      status: 201,
    );
  }

  public function download(EventExportJob $export, ExportService $service): StreamedResponse
  {
    $this->authorize('view', $export);

    return $service->download($export);
  }
}
