<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Public;

use App\Http\Controllers\Api\V1\ApiController;
use App\Support\GeoCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class GeoCatalogController extends ApiController
{
    public function countries(): JsonResponse
    {
        return $this->responder->success(
            data: GeoCatalog::countries(),
            message: 'Countries retrieved.',
        );
    }

    public function phoneCountries(): JsonResponse
    {
        return $this->responder->success(
            data: GeoCatalog::phoneCountries(),
            message: 'Phone countries retrieved.',
        );
    }

    public function subdivisions(Request $request): JsonResponse
    {
        $iso = (string) $request->query('country', '');

        return $this->responder->success(
            data: [
                'country' => GeoCatalog::country($iso),
                'subdivisions' => GeoCatalog::subdivisions($iso),
            ],
            message: 'Subdivisions retrieved.',
        );
    }
}
