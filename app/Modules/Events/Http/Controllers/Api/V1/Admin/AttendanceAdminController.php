<?php

declare(strict_types=1);

namespace App\Modules\Events\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\ApiController;
use App\Modules\Events\Http\Resources\EventAttendanceHistoryResource;
use App\Modules\Events\Models\EventRegistration;
use App\Modules\Events\Services\AttendanceService;
use App\Support\Api\PaginatedResponseBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AttendanceAdminController extends ApiController
{
  public function index(Request $request, AttendanceService $service): JsonResponse
  {
    $this->authorize('viewAny', EventRegistration::class);

    return $this->responder->success(
      data: PaginatedResponseBuilder::fromPaginator($service->paginate($request->query()), EventAttendanceHistoryResource::class),
      message: 'Attendance records retrieved.',
    );
  }
}
