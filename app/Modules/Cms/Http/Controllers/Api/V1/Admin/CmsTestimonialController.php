<?php

declare(strict_types=1);

namespace App\Modules\Cms\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\ApiController;
use App\Modules\Cms\Enums\TestimonialStatus;
use App\Modules\Cms\Http\Resources\CmsTestimonialResource;
use App\Modules\Cms\Models\CmsTestimonial;
use App\Modules\Cms\Services\CmsTestimonialAdminService;
use App\Modules\Cms\Services\PublicTestimonyService;
use App\Support\Api\PaginatedResponseBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class CmsTestimonialController extends ApiController
{
  public function index(Request $request, CmsTestimonialAdminService $service): JsonResponse
  {
    $this->authorize('viewAny', CmsTestimonial::class);

    return $this->responder->success(
      data: PaginatedResponseBuilder::fromPaginator($service->paginate($request->query()), CmsTestimonialResource::class),
      message: 'Testimonials retrieved.',
    );
  }

  public function store(Request $request, CmsTestimonialAdminService $service): JsonResponse
  {
    $this->authorize('create', CmsTestimonial::class);
    $testimonial = $service->create($this->validateTestimonial($request), $request->user());

    return $this->responder->success(
      data: ['testimonial' => new CmsTestimonialResource($testimonial)],
      message: 'Testimonial created.',
      status: 201,
    );
  }

  public function update(Request $request, CmsTestimonial $testimonial, CmsTestimonialAdminService $service): JsonResponse
  {
    $this->authorize('update', $testimonial);
    $testimonial = $service->update($testimonial, $this->validateTestimonial($request, true), $request->user());

    return $this->responder->success(
      data: ['testimonial' => new CmsTestimonialResource($testimonial)],
      message: 'Testimonial updated.',
    );
  }

  public function approve(Request $request, CmsTestimonial $testimonial, CmsTestimonialAdminService $service): JsonResponse
  {
    $this->authorize('update', $testimonial);

    $validated = $request->validate([
      'show_on_homepage' => ['sometimes', 'boolean'],
      'show_on_page' => ['sometimes', 'boolean'],
      'is_featured' => ['sometimes', 'boolean'],
    ]);

    $testimonial = $service->approve($testimonial, $request->user(), $validated);

    return $this->responder->success(
      data: ['testimonial' => new CmsTestimonialResource($testimonial)],
      message: 'Testimonial approved.',
    );
  }

  public function reject(Request $request, CmsTestimonial $testimonial, CmsTestimonialAdminService $service): JsonResponse
  {
    $this->authorize('update', $testimonial);

    $validated = $request->validate([
      'reason' => ['nullable', 'string', 'max:2000'],
    ]);

    $testimonial = $service->reject($testimonial, $request->user(), $validated['reason'] ?? null);

    return $this->responder->success(
      data: ['testimonial' => new CmsTestimonialResource($testimonial)],
      message: 'Testimonial rejected.',
    );
  }

  public function destroy(Request $request, CmsTestimonial $testimonial, CmsTestimonialAdminService $service): JsonResponse
  {
    $this->authorize('delete', $testimonial);
    $service->delete($testimonial, $request->user());

    return $this->responder->success(message: 'Testimonial deleted.');
  }

  public function reorder(Request $request, CmsTestimonialAdminService $service): JsonResponse
  {
    $this->authorize('viewAny', CmsTestimonial::class);

    $validated = $request->validate([
      'ids' => ['required', 'array', 'min:1'],
      'ids.*' => ['string', 'exists:cms_testimonials,uuid'],
    ]);

    $service->reorder($validated['ids'], $request->user());

    return $this->responder->success(message: 'Testimonials reordered.');
  }

  public function bulkUpdate(Request $request, CmsTestimonialAdminService $service): JsonResponse
  {
    $this->authorize('viewAny', CmsTestimonial::class);

    $validated = $request->validate([
      'ids' => ['required', 'array', 'min:1'],
      'ids.*' => ['string', 'exists:cms_testimonials,uuid'],
      'is_active' => ['sometimes', 'boolean'],
      'is_featured' => ['sometimes', 'boolean'],
      'show_on_homepage' => ['sometimes', 'boolean'],
      'show_on_page' => ['sometimes', 'boolean'],
      'status' => ['sometimes', Rule::enum(TestimonialStatus::class)],
      'category' => ['sometimes', 'nullable', 'string', Rule::in(PublicTestimonyService::CATEGORIES)],
    ]);

    $count = $service->bulkUpdate($validated['ids'], $validated, $request->user());

    return $this->responder->success(
      data: ['updated' => $count],
      message: 'Testimonials updated.',
    );
  }

  public function bulkDestroy(Request $request, CmsTestimonialAdminService $service): JsonResponse
  {
    $this->authorize('viewAny', CmsTestimonial::class);

    $validated = $request->validate([
      'ids' => ['required', 'array', 'min:1'],
      'ids.*' => ['string', 'exists:cms_testimonials,uuid'],
    ]);

    $count = $service->bulkDelete($validated['ids'], $request->user());

    return $this->responder->success(
      data: ['deleted' => $count],
      message: 'Testimonials deleted.',
    );
  }

  private function validateTestimonial(Request $request, bool $partial = false): array
  {
    return $request->validate([
      'author_name' => [$partial ? 'sometimes' : 'required', 'string', 'max:255'],
      'author_title' => ['nullable', 'string', 'max:255'],
      'author_location' => ['nullable', 'string', 'max:255'],
      'quote' => [$partial ? 'sometimes' : 'required', 'string'],
      'category' => ['nullable', 'string', Rule::in(PublicTestimonyService::CATEGORIES)],
      'status' => ['sometimes', Rule::enum(TestimonialStatus::class)],
      'is_anonymous' => ['sometimes', 'boolean'],
      'photo_media_id' => ['nullable', 'string'],
      'video_media_id' => ['nullable', 'string'],
      'is_active' => ['sometimes', 'boolean'],
      'is_featured' => ['sometimes', 'boolean'],
      'show_on_homepage' => ['sometimes', 'boolean'],
      'show_on_page' => ['sometimes', 'boolean'],
      'sort_order' => ['sometimes', 'integer', 'min:0'],
    ]);
  }
}
