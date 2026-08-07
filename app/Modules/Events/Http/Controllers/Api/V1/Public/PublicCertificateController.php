<?php

declare(strict_types=1);

namespace App\Modules\Events\Http\Controllers\Api\V1\Public;

use App\Http\Controllers\Api\V1\ApiController;
use App\Modules\Events\Services\CertificateService;
use Illuminate\Http\JsonResponse;

final class PublicCertificateController extends ApiController
{
  public function verify(string $code, CertificateService $service): JsonResponse
  {
    $data = $service->verify($code);

    if ($data === null) {
      return $this->responder->error(
        message: 'Certificate not found or has been revoked.',
        code: 'not_found',
        status: 404,
      );
    }

    return $this->responder->success(
      data: ['certificate' => $data],
      message: 'Certificate verified.',
    );
  }
}
