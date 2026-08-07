<?php

declare(strict_types=1);

namespace App\Contracts;

use App\DTOs\Api\ApiResponseData;
use Illuminate\Http\JsonResponse;

interface ApiResponderContract
{
  public function success(
    mixed $data = null,
    ?string $message = null,
    int $status = 200,
    ?array $meta = null,
  ): JsonResponse;

  public function error(
    string $message,
    string $code,
    int $status = 400,
    ?array $errors = null,
    ?array $meta = null,
  ): JsonResponse;

  public function fromData(ApiResponseData $response, int $status = 200): JsonResponse;
}
