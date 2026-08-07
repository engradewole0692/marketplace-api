<?php

declare(strict_types=1);

namespace App\Modules\Events\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\ApiController;
use App\Modules\Events\Http\Requests\StoreSpeakerRequest;
use App\Modules\Events\Http\Requests\UpdateSpeakerRequest;
use App\Modules\Events\Http\Resources\SpeakerResource;
use App\Modules\Events\Models\Speaker;
use App\Modules\Events\Services\SpeakerService;
use App\Support\Api\PaginatedResponseBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class SpeakerAdminController extends ApiController
{
  public function index(Request $request, SpeakerService $service): JsonResponse
  {
    $this->authorize('viewAny', Speaker::class);

    return $this->responder->success(
      data: PaginatedResponseBuilder::fromPaginator($service->paginate($request->query()), SpeakerResource::class),
      message: 'Speakers retrieved.',
    );
  }

  public function show(Speaker $speaker): JsonResponse
  {
    $this->authorize('view', $speaker);

    return $this->responder->success(
      data: ['speaker' => new SpeakerResource($speaker)],
      message: 'Speaker retrieved.',
    );
  }

  public function store(StoreSpeakerRequest $request, SpeakerService $service): JsonResponse
  {
    $this->authorize('create', Speaker::class);

    $speaker = $service->create($request->validated(), $request->user());

    return $this->responder->success(
      data: ['speaker' => new SpeakerResource($speaker)],
      message: 'Speaker created.',
      status: 201,
    );
  }

  public function update(UpdateSpeakerRequest $request, Speaker $speaker, SpeakerService $service): JsonResponse
  {
    $this->authorize('update', $speaker);

    $speaker = $service->update($speaker, $request->validated(), $request->user());

    return $this->responder->success(
      data: ['speaker' => new SpeakerResource($speaker)],
      message: 'Speaker updated.',
    );
  }

  public function destroy(Speaker $speaker, SpeakerService $service, Request $request): JsonResponse
  {
    $this->authorize('delete', $speaker);

    $service->delete($speaker, $request->user());

    return $this->responder->success(data: null, message: 'Speaker deleted.');
  }
}
