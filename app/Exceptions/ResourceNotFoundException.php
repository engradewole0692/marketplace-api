<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Enums\ApiErrorCode;

class ResourceNotFoundException extends ApiException
{
  public function __construct(string $message = '')
  {
    parent::__construct(
      ApiErrorCode::NotFound,
      $message !== '' ? $message : ApiErrorCode::NotFound->defaultMessage(),
      null,
      404,
    );
  }
}
