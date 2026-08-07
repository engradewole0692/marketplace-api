<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Public;

use App\Http\Controllers\Api\V1\ApiController;
use App\Modules\Events\Services\CertificateService as EventCertificateService;
use App\Modules\Lms\Services\CourseCertificateService;
use Illuminate\Http\JsonResponse;

/**
 * Unified public certificate verification for LMS + Events.
 */
final class PublicCertificateVerifyController extends ApiController
{
  public function __invoke(
    string $code,
    CourseCertificateService $lms,
    EventCertificateService $events,
  ): JsonResponse {
    $payload = $lms->verify($code);
    if ($payload === null) {
      $eventPayload = $events->verify($code);
      if ($eventPayload !== null) {
        $payload = array_merge(['type' => 'event'], $eventPayload);
      }
    }

    if ($payload === null) {
      abort(404, 'Certificate not found.');
    }

    return $this->responder->success(
      data: ['certificate' => $payload],
      message: 'Certificate verified.',
    );
  }
}
