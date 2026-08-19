<?php

declare(strict_types=1);

namespace App\Modules\Cms\Http\Controllers\Api\V1\Public;

use App\Http\Controllers\Api\V1\ApiController;
use App\Modules\Cms\Enums\CatalogItemType;
use App\Modules\Cms\Http\Resources\CmsCatalogItemResource;
use App\Modules\Cms\Http\Resources\CmsCountryResource;
use App\Modules\Cms\Http\Resources\CmsLeadershipResource;
use App\Modules\Cms\Http\Resources\CmsMinistryResource;
use App\Modules\Cms\Http\Resources\CmsPageSectionResource;
use App\Modules\Cms\Http\Resources\CmsPartnerResource;
use App\Modules\Cms\Http\Resources\CmsSeoResource;
use App\Modules\Cms\Http\Resources\CmsTestimonialResource;
use App\Modules\Cms\Services\PublicContentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class PublicSiteController extends ApiController
{
  public function bootstrap(PublicContentService $content): JsonResponse
  {
    return $this->responder->success(
      data: $content->siteBootstrap(),
      message: 'Site bootstrap retrieved.',
    );
  }

  public function home(PublicContentService $content): JsonResponse
  {
    $home = $content->home();

    return $this->responder->success(
      data: [
        'sections' => CmsPageSectionResource::collection($home['sections']),
        'hidden_section_keys' => $home['hidden_section_keys'],
        'countries' => CmsCountryResource::collection($home['countries']),
        'ministries' => CmsMinistryResource::collection($home['ministries']),
        'leadership' => CmsLeadershipResource::collection($home['leadership']),
        'testimonials' => CmsTestimonialResource::collection($home['testimonials']),
        'partners' => CmsPartnerResource::collection($home['partners']),
        'seo' => $home['seo'] ? new CmsSeoResource($home['seo']) : null,
      ],
      message: 'Home content retrieved.',
    );
  }

  public function page(string $slug, PublicContentService $content): JsonResponse
  {
    $page = $content->page($slug);

    if ($page === null) {
      return $this->responder->error('Page not found.', 'NOT_FOUND', 404);
    }

    return $this->responder->success(
      data: [
        'page' => $page['page'],
        'sections' => CmsPageSectionResource::collection($page['sections']),
        'seo' => $page['seo'] ? new CmsSeoResource($page['seo']) : null,
      ],
      message: 'Page retrieved.',
    );
  }

  public function countries(PublicContentService $content): JsonResponse
  {
    return $this->responder->success(
      data: CmsCountryResource::collection($content->countries()),
      message: 'Countries retrieved.',
    );
  }

  public function country(string $slug, PublicContentService $content): JsonResponse
  {
    $country = $content->country($slug);

    if ($country === null) {
      return $this->responder->error('Country not found.', 'NOT_FOUND', 404);
    }

    return $this->responder->success(
      data: new CmsCountryResource($country),
      message: 'Country retrieved.',
    );
  }

  public function ministries(PublicContentService $content): JsonResponse
  {
    return $this->responder->success(
      data: CmsMinistryResource::collection($content->ministries()),
      message: 'Ministries retrieved.',
    );
  }

  public function ministry(string $slug, PublicContentService $content): JsonResponse
  {
    $ministry = $content->ministry($slug);

    if ($ministry === null) {
      return $this->responder->error('Ministry not found.', 'NOT_FOUND', 404);
    }

    return $this->responder->success(
      data: new CmsMinistryResource($ministry),
      message: 'Ministry retrieved.',
    );
  }

  public function leadership(PublicContentService $content): JsonResponse
  {
    return $this->responder->success(
      data: CmsLeadershipResource::collection($content->leadership()),
      message: 'Leadership profiles retrieved.',
    );
  }

  public function testimonials(Request $request, PublicContentService $content): JsonResponse
  {
    $placement = $request->query('placement');
    $category = $request->query('category');
    $limit = $request->query('limit');

    return $this->responder->success(
      data: CmsTestimonialResource::collection(
        $content->testimonials(
          limit: $limit !== null ? (int) $limit : null,
          placement: is_string($placement) ? $placement : 'page',
          category: is_string($category) ? $category : null,
        ),
      ),
      message: 'Testimonials retrieved.',
    );
  }

  public function partners(PublicContentService $content): JsonResponse
  {
    return $this->responder->success(
      data: CmsPartnerResource::collection($content->partners()),
      message: 'Partners retrieved.',
    );
  }

  public function catalog(Request $request, string $type, PublicContentService $content): JsonResponse
  {
    $category = $request->query('category');

    return $this->responder->success(
      data: CmsCatalogItemResource::collection(
        $content->catalog(
          CatalogItemType::from($type),
          is_string($category) && $category !== '' ? $category : null,
        ),
      ),
      message: 'Catalog items retrieved.',
    );
  }

  public function catalogItem(string $type, string $slug, PublicContentService $content): JsonResponse
  {
    $item = $content->catalogItem(CatalogItemType::from($type), $slug);

    if ($item === null) {
      return $this->responder->error('Catalog item not found.', 'NOT_FOUND', 404);
    }

    return $this->responder->success(
      data: new CmsCatalogItemResource($item),
      message: 'Catalog item retrieved.',
    );
  }

  public function resourceDownload(string $slug, PublicContentService $content): Response|JsonResponse
  {
    $item = $content->catalogItem(CatalogItemType::Resource, $slug);

    if ($item === null) {
      return $this->responder->error('Resource not found.', 'NOT_FOUND', 404);
    }

    $metadata = $item->metadata ?? [];
    $fileUrl = $metadata['download_url'] ?? $metadata['file_url'] ?? null;

    if (is_string($fileUrl) && $fileUrl !== '') {
      $path = parse_url($fileUrl, PHP_URL_PATH);
      if (is_string($path) && str_contains($path, '/storage/')) {
        $storagePath = ltrim(str_replace('/storage/', '', $path), '/');
        if ($storagePath !== '' && \Illuminate\Support\Facades\Storage::disk('public')->exists($storagePath)) {
          return \Illuminate\Support\Facades\Storage::disk('public')->download(
            $storagePath,
            $item->featuredMedia?->file_name ?? basename($storagePath),
          );
        }
      }
    }

    $body = implode("\n\n", array_filter([
      $item->title,
      $item->summary,
      $item->body,
    ]));

    $filename = preg_replace('/[^a-z0-9-]+/', '-', strtolower($item->slug)).'.txt';

    return response($body, 200, [
      'Content-Type' => 'text/plain; charset=UTF-8',
      'Content-Disposition' => 'attachment; filename="'.$filename.'"',
    ]);
  }
}
