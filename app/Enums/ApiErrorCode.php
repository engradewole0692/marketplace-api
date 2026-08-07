<?php

declare(strict_types=1);

namespace App\Enums;

enum ApiErrorCode: string
{
  case ValidationFailed = 'VALIDATION_FAILED';
  case Unauthorized = 'UNAUTHORIZED';
  case Forbidden = 'FORBIDDEN';
  case NotFound = 'NOT_FOUND';
  case MethodNotAllowed = 'METHOD_NOT_ALLOWED';
  case Conflict = 'CONFLICT';
  case UnprocessableEntity = 'UNPROCESSABLE_ENTITY';
  case TooManyRequests = 'TOO_MANY_REQUESTS';
  case ServerError = 'SERVER_ERROR';
  case ServiceUnavailable = 'SERVICE_UNAVAILABLE';
  case BadRequest = 'BAD_REQUEST';

  public function defaultMessage(): string
  {
    return match ($this) {
      self::ValidationFailed => 'The given data was invalid.',
      self::Unauthorized => 'Authentication is required.',
      self::Forbidden => 'You do not have permission to perform this action.',
      self::NotFound => 'The requested resource was not found.',
      self::MethodNotAllowed => 'The HTTP method is not allowed for this endpoint.',
      self::Conflict => 'The request could not be completed due to a conflict.',
      self::UnprocessableEntity => 'The request could not be processed.',
      self::TooManyRequests => 'Too many requests. Please try again later.',
      self::ServerError => 'An unexpected server error occurred.',
      self::ServiceUnavailable => 'The service is temporarily unavailable.',
      self::BadRequest => 'The request was malformed or invalid.',
    };
  }
}
