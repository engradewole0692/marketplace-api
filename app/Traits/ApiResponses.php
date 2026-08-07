<?php

declare(strict_types=1);

namespace App\Traits;

use App\Contracts\ApiResponderContract;
use Illuminate\Http\JsonResponse;

trait ApiResponses
{
  protected function api(): ApiResponderContract
  {
    return app(ApiResponderContract::class);
  }

  protected function successResponse(
    mixed $data = null,
    ?string $message = null,
    int $status = 200,
    ?array $meta = null,
  ): JsonResponse {
    return $this->api()->success($data, $message, $status, $meta);
  }

  protected function errorResponse(
    string $message,
    string $code,
    int $status = 400,
    ?array $errors = null,
    ?array $meta = null,
  ): JsonResponse {
    return $this->api()->error($message, $code, $status, $errors, $meta);
  }
}
