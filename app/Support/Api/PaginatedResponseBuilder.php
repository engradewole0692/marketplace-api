<?php

declare(strict_types=1);

namespace App\Support\Api;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Resources\Json\JsonResource;

final class PaginatedResponseBuilder
{
  /**
   * @param  class-string<JsonResource>  $resourceClass
   * @return array{data: mixed, meta: array<string, int|null>}
   */
  public static function fromPaginator(LengthAwarePaginator $paginator, string $resourceClass): array
  {
    return [
      'data' => $resourceClass::collection($paginator->items()),
      'meta' => [
        'current_page' => $paginator->currentPage(),
        'last_page' => $paginator->lastPage(),
        'per_page' => $paginator->perPage(),
        'total' => $paginator->total(),
        'from' => $paginator->firstItem(),
        'to' => $paginator->lastItem(),
      ],
    ];
  }
}
