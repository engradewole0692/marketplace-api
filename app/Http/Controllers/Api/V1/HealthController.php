<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Health\GetHealthStatusAction;
use App\Contracts\ApiResponderContract;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

final class HealthController extends Controller
{
  public function __invoke(
    GetHealthStatusAction $action,
    ApiResponderContract $responder,
  ): JsonResponse {
    $status = $action->execute();
    $httpStatus = $status->status === 'ok' ? 200 : 503;

    return $responder->success(
      data: $status->toArray(),
      message: 'API is operational.',
      status: $httpStatus,
    );
  }
}
