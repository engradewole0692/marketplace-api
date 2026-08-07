<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Iam;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Resources\IamAuditLogResource;
use App\Models\IamAuditLog;
use App\Support\Api\PaginatedResponseBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class IamAuditLogController extends ApiController
{
  public function index(Request $request): JsonResponse
  {
    $this->authorize('viewAny', IamAuditLog::class);

    $query = IamAuditLog::query()->with('actor')->orderByDesc('created_at');

    if ($request->filled('event_type')) {
      $query->where('event_type', $request->string('event_type'));
    }

    if ($request->filled('actor_id')) {
      $query->where('actor_id', $request->integer('actor_id'));
    }

    if ($request->filled('subject_type')) {
      $query->where('subject_type', $request->string('subject_type'));
    }

    $perPage = min(max($request->integer('per_page', 25), 1), 100);
    $paginator = $query->paginate($perPage);

    return $this->responder->success(
      data: PaginatedResponseBuilder::fromPaginator($paginator, IamAuditLogResource::class),
      message: 'Audit logs retrieved.',
    );
  }

  public function show(IamAuditLog $auditLog): JsonResponse
  {
    $this->authorize('view', $auditLog);
    $auditLog->load('actor');

    return $this->responder->success(
      data: ['audit_log' => new IamAuditLogResource($auditLog)],
      message: 'Audit log retrieved.',
    );
  }
}
