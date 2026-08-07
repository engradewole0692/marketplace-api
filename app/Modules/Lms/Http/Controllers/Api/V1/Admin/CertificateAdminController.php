<?php

declare(strict_types=1);

namespace App\Modules\Lms\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\ApiController;
use App\Modules\Lms\Models\CertificateTemplate;
use App\Modules\Lms\Models\CourseCertificate;
use App\Modules\Lms\Models\Enrollment;
use App\Modules\Lms\Services\CourseCertificateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class CertificateAdminController extends ApiController
{
  public function templates(Request $request, CourseCertificateService $service): JsonResponse
  {
    $this->authorize('viewAny', CourseCertificate::class);
    $paginator = $service->paginateTemplates($request->query());

    return $this->responder->success(
      data: [
        'data' => collect($paginator->items())->map(fn (CertificateTemplate $t) => $this->templatePayload($t)),
        'meta' => $this->meta($paginator),
      ],
      message: 'Certificate templates retrieved.',
    );
  }

  public function storeTemplate(Request $request, CourseCertificateService $service): JsonResponse
  {
    $this->authorize('create', CourseCertificate::class);
    $validated = $request->validate([
      'name' => ['required', 'string', 'max:255'],
      'slug' => ['nullable', 'string', 'max:255'],
      'course_id' => ['nullable', 'string'],
      'html_body' => ['nullable', 'string'],
      'background_media_id' => ['nullable', 'integer', 'exists:cms_media,id'],
      'logo_media_id' => ['nullable', 'integer', 'exists:cms_media,id'],
      'watermark_media_id' => ['nullable', 'integer', 'exists:cms_media,id'],
      'instructor_signature_media_id' => ['nullable', 'integer', 'exists:cms_media,id'],
      'director_signature_media_id' => ['nullable', 'integer', 'exists:cms_media,id'],
      'is_active' => ['sometimes', 'boolean'],
      'is_default' => ['sometimes', 'boolean'],
      'sort_order' => ['nullable', 'integer', 'min:0'],
    ]);

    $template = $service->createTemplate($validated, $request->user());

    return $this->responder->success(
      data: ['template' => $this->templatePayload($template)],
      message: 'Template created.',
      status: 201,
    );
  }

  public function updateTemplate(Request $request, CertificateTemplate $template, CourseCertificateService $service): JsonResponse
  {
    $this->authorize('update', $template);
    $validated = $request->validate([
      'name' => ['sometimes', 'string', 'max:255'],
      'slug' => ['nullable', 'string', 'max:255'],
      'course_id' => ['nullable', 'string'],
      'html_body' => ['nullable', 'string'],
      'background_media_id' => ['nullable', 'integer', 'exists:cms_media,id'],
      'logo_media_id' => ['nullable', 'integer', 'exists:cms_media,id'],
      'watermark_media_id' => ['nullable', 'integer', 'exists:cms_media,id'],
      'instructor_signature_media_id' => ['nullable', 'integer', 'exists:cms_media,id'],
      'director_signature_media_id' => ['nullable', 'integer', 'exists:cms_media,id'],
      'is_active' => ['sometimes', 'boolean'],
      'is_default' => ['sometimes', 'boolean'],
      'sort_order' => ['nullable', 'integer', 'min:0'],
    ]);

    $template = $service->updateTemplate($template, $validated, $request->user());

    return $this->responder->success(
      data: ['template' => $this->templatePayload($template)],
      message: 'Template updated.',
    );
  }

  public function destroyTemplate(CertificateTemplate $template, CourseCertificateService $service): JsonResponse
  {
    $this->authorize('delete', $template);
    $service->deleteTemplate($template);

    return $this->responder->success(message: 'Template deleted.');
  }

  public function index(Request $request, CourseCertificateService $service): JsonResponse
  {
    $this->authorize('viewAny', CourseCertificate::class);
    $paginator = $service->paginateIssuances($request->query());

    return $this->responder->success(
      data: [
        'data' => collect($paginator->items())->map(fn (CourseCertificate $c) => $this->issuancePayload($c)),
        'meta' => $this->meta($paginator),
      ],
      message: 'Certificates retrieved.',
    );
  }

  public function issue(Request $request, CourseCertificateService $service): JsonResponse
  {
    $this->authorize('issue', CourseCertificate::class);
    $validated = $request->validate([
      'enrollment_id' => ['required', 'string'],
      'template_id' => ['nullable', 'string'],
    ]);

    $enrollment = Enrollment::query()->where('uuid', $validated['enrollment_id'])->firstOrFail();
    $templateId = null;
    if (! empty($validated['template_id'])) {
      $templateId = CertificateTemplate::query()->where('uuid', $validated['template_id'])->value('id');
    }

    $certificate = $service->issue($enrollment, $request->user(), $templateId);

    return $this->responder->success(
      data: ['certificate' => $this->issuancePayload($certificate)],
      message: 'Certificate issued.',
      status: 201,
    );
  }

  public function reissue(Request $request, CourseCertificate $certificate, CourseCertificateService $service): JsonResponse
  {
    $this->authorize('issue', CourseCertificate::class);
    $new = $service->reissue($certificate, $request->user());

    return $this->responder->success(
      data: ['certificate' => $this->issuancePayload($new)],
      message: 'Certificate reissued.',
    );
  }

  /** @return array<string, mixed> */
  private function templatePayload(CertificateTemplate $t): array
  {
    $t->loadMissing(['course:id,uuid,title', 'backgroundMedia', 'logoMedia', 'watermarkMedia', 'instructorSignatureMedia', 'directorSignatureMedia']);

    return [
      'id' => $t->uuid,
      'name' => $t->name,
      'slug' => $t->slug,
      'html_body' => $t->html_body,
      'is_active' => $t->is_active,
      'is_default' => $t->is_default,
      'sort_order' => $t->sort_order,
      'course' => $t->course ? ['id' => $t->course->uuid, 'title' => $t->course->title] : null,
      'background_media_id' => $t->background_media_id,
      'logo_media_id' => $t->logo_media_id,
      'watermark_media_id' => $t->watermark_media_id,
      'instructor_signature_media_id' => $t->instructor_signature_media_id,
      'director_signature_media_id' => $t->director_signature_media_id,
      'background_url' => $t->backgroundMedia?->url(),
      'logo_url' => $t->logoMedia?->url(),
      'watermark_url' => $t->watermarkMedia?->url(),
      'instructor_signature_url' => $t->instructorSignatureMedia?->url(),
      'director_signature_url' => $t->directorSignatureMedia?->url(),
    ];
  }

  /** @return array<string, mixed> */
  private function issuancePayload(CourseCertificate $c): array
  {
    $c->loadMissing(['course:id,uuid,title,slug', 'user:id,uuid,name,email', 'certificateMedia', 'template']);

    return [
      'id' => $c->uuid,
      'certificate_number' => $c->certificate_number,
      'verification_code' => $c->verification_code,
      'status' => $c->status instanceof \BackedEnum ? $c->status->value : $c->status,
      'issued_at' => $c->issued_at?->toIso8601String(),
      'download_count' => $c->download_count,
      'certificate_url' => $c->certificate_url,
      'verification_url' => url('/certificate/'.$c->verification_code),
      'course' => $c->course ? [
        'id' => $c->course->uuid,
        'title' => $c->course->title,
        'slug' => $c->course->slug,
      ] : null,
      'learner' => $c->user ? [
        'id' => $c->user->uuid ?? (string) $c->user->id,
        'name' => $c->user->name,
        'email' => $c->user->email,
      ] : null,
      'template' => $c->template ? [
        'id' => $c->template->uuid,
        'name' => $c->template->name,
      ] : null,
    ];
  }

  /** @param  \Illuminate\Contracts\Pagination\LengthAwarePaginator<int, mixed>  $paginator */
  private function meta($paginator): array
  {
    return [
      'current_page' => $paginator->currentPage(),
      'last_page' => $paginator->lastPage(),
      'per_page' => $paginator->perPage(),
      'total' => $paginator->total(),
    ];
  }
}
