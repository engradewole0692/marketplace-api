<?php

declare(strict_types=1);

namespace App\Modules\Events\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\ApiController;
use App\Modules\Events\Http\Requests\IssueCertificateRequest;
use App\Modules\Events\Http\Resources\EventCertificateIssuanceResource;
use App\Modules\Events\Models\EventCertificateIssuance;
use App\Modules\Events\Models\EventRegistration;
use App\Modules\Events\Services\CertificateService;
use App\Support\Api\PaginatedResponseBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class CertificateAdminController extends ApiController
{
  public function index(Request $request, CertificateService $service): JsonResponse
  {
    $this->authorize('permission', 'certificates.manage');

    return $this->responder->success(
      data: PaginatedResponseBuilder::fromPaginator(
        $service->paginateIssuances($request->query()),
        EventCertificateIssuanceResource::class,
      ),
      message: 'Certificates retrieved.',
    );
  }

  public function issue(IssueCertificateRequest $request, CertificateService $service): JsonResponse
  {
    $this->authorize('permission', 'certificates.issue');

    $registrationId = (int) $request->validated('registration_id');
    if ($registrationId === 0) {
      abort(422, 'registration_id is required.');
    }

    $registration = EventRegistration::query()->findOrFail($registrationId);

    $issuance = $service->issue(
      $registration,
      $request->user(),
      $request->validated('template_id') ? (int) $request->validated('template_id') : null,
    );

    return $this->responder->success(
      data: ['certificate' => new EventCertificateIssuanceResource($issuance)],
      message: 'Certificate issued.',
      status: 201,
    );
  }

  public function batch(IssueCertificateRequest $request, CertificateService $service): JsonResponse
  {
    $this->authorize('permission', 'certificates.issue');

    $eventId = (int) $request->validated('event_id');
    if ($eventId === 0) {
      abort(422, 'event_id is required for batch issuance.');
    }

    $result = $service->batchIssue(
      $eventId,
      $request->user(),
      (bool) $request->validated('only_attended', true),
    );

    return $this->responder->success(
      data: $result,
      message: 'Batch certificate issuance processed.',
    );
  }

  public function reissue(EventCertificateIssuance $issuance, Request $request, CertificateService $service): JsonResponse
  {
    $this->authorize('permission', 'certificates.issue');

    $new = $service->reissue($issuance, $request->user());

    return $this->responder->success(
      data: ['certificate' => new EventCertificateIssuanceResource($new)],
      message: 'Certificate reissued.',
      status: 201,
    );
  }
}
