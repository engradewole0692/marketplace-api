<?php

declare(strict_types=1);

namespace App\Modules\Events\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\ApiController;
use App\Modules\Events\Http\Requests\StoreCertificateTemplateRequest;
use App\Modules\Events\Http\Resources\EventCertificateTemplateResource;
use App\Modules\Events\Models\EventCertificateTemplate;
use App\Modules\Events\Services\CertificateService;
use App\Support\Api\PaginatedResponseBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class CertificateTemplateAdminController extends ApiController
{
  public function index(Request $request, CertificateService $service): JsonResponse
  {
    $this->authorize('permission', 'certificates.manage');

    return $this->responder->success(
      data: PaginatedResponseBuilder::fromPaginator(
        $service->paginateTemplates($request->query()),
        EventCertificateTemplateResource::class,
      ),
      message: 'Certificate templates retrieved.',
    );
  }

  public function store(StoreCertificateTemplateRequest $request, CertificateService $service): JsonResponse
  {
    $this->authorize('permission', 'certificates.manage');

    $template = $service->createTemplate($request->validated(), $request->user());

    return $this->responder->success(
      data: ['template' => new EventCertificateTemplateResource($template)],
      message: 'Certificate template created.',
      status: 201,
    );
  }

  public function update(
    StoreCertificateTemplateRequest $request,
    EventCertificateTemplate $template,
    CertificateService $service,
  ): JsonResponse {
    $this->authorize('permission', 'certificates.manage');

    $template = $service->updateTemplate($template, $request->validated(), $request->user());

    return $this->responder->success(
      data: ['template' => new EventCertificateTemplateResource($template)],
      message: 'Certificate template updated.',
    );
  }

  public function destroy(EventCertificateTemplate $template, CertificateService $service): JsonResponse
  {
    $this->authorize('permission', 'certificates.manage');

    $service->deleteTemplate($template);

    return $this->responder->success(data: null, message: 'Certificate template deleted.');
  }
}
