<?php

declare(strict_types=1);

namespace App\Support\Api;

use App\Contracts\ApiResponderContract;
use App\Enums\ApiErrorCode;
use App\Exceptions\ApiException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

final class ApiExceptionHandler
{
  public function __construct(
    private readonly ApiResponderContract $responder,
  ) {}

  public function shouldRender(Request $request, Throwable $exception): bool
  {
    return $request->is('api/*') || $request->expectsJson();
  }

  public function render(Request $request, Throwable $exception): JsonResponse
  {
    if ($exception instanceof ApiException) {
      return $this->responder->error(
        message: $exception->getMessage(),
        code: $exception->errorCode()->value,
        status: $exception->httpStatus(),
        errors: $exception->errors(),
      );
    }

    if ($exception instanceof ValidationException) {
      return $this->responder->error(
        message: $exception->getMessage(),
        code: ApiErrorCode::ValidationFailed->value,
        status: Response::HTTP_UNPROCESSABLE_ENTITY,
        errors: $exception->errors(),
      );
    }

    if ($exception instanceof AuthenticationException) {
      return $this->responder->error(
        message: ApiErrorCode::Unauthorized->defaultMessage(),
        code: ApiErrorCode::Unauthorized->value,
        status: Response::HTTP_UNAUTHORIZED,
      );
    }

    if ($exception instanceof AuthorizationException) {
      return $this->responder->error(
        message: ApiErrorCode::Forbidden->defaultMessage(),
        code: ApiErrorCode::Forbidden->value,
        status: Response::HTTP_FORBIDDEN,
      );
    }

    if ($exception instanceof ModelNotFoundException || $exception instanceof NotFoundHttpException) {
      return $this->responder->error(
        message: ApiErrorCode::NotFound->defaultMessage(),
        code: ApiErrorCode::NotFound->value,
        status: Response::HTTP_NOT_FOUND,
      );
    }

    if ($exception instanceof MethodNotAllowedHttpException) {
      return $this->responder->error(
        message: ApiErrorCode::MethodNotAllowed->defaultMessage(),
        code: ApiErrorCode::MethodNotAllowed->value,
        status: Response::HTTP_METHOD_NOT_ALLOWED,
      );
    }

    if ($exception instanceof HttpExceptionInterface) {
      $status = $exception->getStatusCode();

      return $this->responder->error(
        message: $exception->getMessage() !== '' ? $exception->getMessage() : Response::$statusTexts[$status] ?? 'Error',
        code: $this->codeForStatus($status)->value,
        status: $status,
      );
    }

    report($exception);

    return $this->responder->error(
      message: config('app.debug')
        ? $exception->getMessage()
        : ApiErrorCode::ServerError->defaultMessage(),
      code: ApiErrorCode::ServerError->value,
      status: Response::HTTP_INTERNAL_SERVER_ERROR,
    );
  }

  private function codeForStatus(int $status): ApiErrorCode
  {
    return match ($status) {
      Response::HTTP_BAD_REQUEST => ApiErrorCode::BadRequest,
      Response::HTTP_UNAUTHORIZED => ApiErrorCode::Unauthorized,
      Response::HTTP_FORBIDDEN => ApiErrorCode::Forbidden,
      Response::HTTP_NOT_FOUND => ApiErrorCode::NotFound,
      Response::HTTP_METHOD_NOT_ALLOWED => ApiErrorCode::MethodNotAllowed,
      Response::HTTP_CONFLICT => ApiErrorCode::Conflict,
      Response::HTTP_UNPROCESSABLE_ENTITY => ApiErrorCode::UnprocessableEntity,
      Response::HTTP_TOO_MANY_REQUESTS => ApiErrorCode::TooManyRequests,
      Response::HTTP_SERVICE_UNAVAILABLE => ApiErrorCode::ServiceUnavailable,
      default => ApiErrorCode::ServerError,
    };
  }
}
