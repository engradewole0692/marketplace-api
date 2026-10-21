<?php

declare(strict_types=1);

namespace App\Support\Api;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Resources\Json\JsonResource;

final class PaginatedResponseBuilder
{
  /**
   * @param  class-string<JsonResource>|\Closure|null  $resourceClass  resource class, item transformer, or null for raw items
   * @return array{data: mixed, meta: array<string, int|null>}
   */
  public static function fromPaginator(LengthAwarePaginator $paginator, string|\Closure|null $resourceClass = null): array
  {
    $items = $paginator->items();

    return [
      'data' => match (true) {
        $resourceClass instanceof \Closure => array_values(array_map($resourceClass, $items)),
        $resourceClass === null => $items,
        default => $resourceClass::collection($items),
      },
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
