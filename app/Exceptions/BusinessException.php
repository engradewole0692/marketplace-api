<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Enums\ApiErrorCode;

class BusinessException extends ApiException
{
  /**
   * @param  array<string, mixed>|null  $errors
   */
  public function __construct(
    string $message,
    ApiErrorCode $errorCode = ApiErrorCode::UnprocessableEntity,
    ?array $errors = null,
    int $httpStatus = 422,
  ) {
    parent::__construct($errorCode, $message, $errors, $httpStatus);
  }
}
