<?php

declare(strict_types=1);

namespace App\Modules\Lms\Http\Controllers\Api\V1\Public;

use App\Http\Controllers\Api\V1\ApiController;
use App\Modules\Lms\Http\Resources\CourseCategoryResource;
use App\Modules\Lms\Services\CategoryService;
use App\Support\Api\PaginatedResponseBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class PublicFreeCategoryController extends ApiController
{
  public function index(Request $request, CategoryService $service): JsonResponse
  {
    return $this->responder->success(
      data: PaginatedResponseBuilder::fromPaginator(
        $service->paginatePublicFreeHubs($request->query()),
        CourseCategoryResource::class,
      ),
      message: 'Free learning categories retrieved.',
    );
  }

  public function show(string $slug, CategoryService $service): JsonResponse
  {
    $category = $service->findPublicFreeHubBySlug($slug);
    if ($category === null) {
      abort(404, 'Free learning category not found.');
    }

    return $this->responder->success(
      data: ['category' => new CourseCategoryResource($category)],
      message: 'Free learning category retrieved.',
    );
  }
}
