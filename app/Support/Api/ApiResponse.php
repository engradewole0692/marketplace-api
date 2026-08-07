<?php

declare(strict_types=1);

namespace App\Support\Api;

use App\Contracts\ApiResponderContract;
use App\DTOs\Api\ApiResponseData;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

final class ApiResponse implements ApiResponderContract
{
  /**
   * @param  array<string, mixed>|null  $meta
   */
  public function success(
    mixed $data = null,
    ?string $message = null,
    int $status = 200,
    ?array $meta = null,
  ): JsonResponse {
    return $this->fromData(new ApiResponseData(
      success: true,
      data: $data,
      message: $message,
      meta: $this->mergeMeta($meta),
    ), $status);
  }

  /**
   * @param  array<string, mixed>|null  $errors
   * @param  array<string, mixed>|null  $meta
   */
  public function error(
    string $message,
    string $code,
    int $status = 400,
    ?array $errors = null,
    ?array $meta = null,
  ): JsonResponse {
    return $this->fromData(new ApiResponseData(
      success: false,
      message: $message,
      code: $code,
      meta: $this->mergeMeta($meta),
      errors: $errors,
    ), $status);
  }

  public function fromData(ApiResponseData $response, int $status = 200): JsonResponse
  {
    return response()->json($response->toArray(), $status);
  }

  /**
   * @param  array<string, mixed>|null  $meta
   * @return array<string, mixed>|null
   */
  private function mergeMeta(?array $meta): ?array
  {
    $defaults = [];

    if (config('api.meta.include_timestamp')) {
      $defaults['timestamp'] = now()->toIso8601String();
    }

    if (config('api.meta.include_request_id')) {
      $defaults['request_id'] = (string) Str::uuid();
    }

    if ($defaults === [] && $meta === null) {
      return null;
    }

    return array_merge($defaults, $meta ?? []);
  }
}
