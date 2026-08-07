<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Enums\ApiErrorCode;
use Exception;
use Throwable;

class ApiException extends Exception
{
  /**
   * @param  array<string, mixed>|null  $errors
   */
  public function __construct(
    private readonly ApiErrorCode $errorCode,
    string $message = '',
    private readonly ?array $errors = null,
    int $httpStatus = 400,
    ?Throwable $previous = null,
  ) {
    parent::__construct(
      $message !== '' ? $message : $errorCode->defaultMessage(),
      $httpStatus,
      $previous,
    );
  }

  public function errorCode(): ApiErrorCode
  {
    return $this->errorCode;
  }

  /**
   * @return array<string, mixed>|null
   */
  public function errors(): ?array
  {
    return $this->errors;
  }

  public function httpStatus(): int
  {
    return $this->getCode();
  }
}
