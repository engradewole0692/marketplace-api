<?php

declare(strict_types=1);

namespace App\Modules\Lms\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\ApiController;
use App\Modules\Lms\Models\Course;
use App\Modules\Lms\Services\LearningExperienceService;
use Illuminate\Http\JsonResponse;

final class ProgressAnalyticsController extends ApiController
{
  public function dashboard(LearningExperienceService $service): JsonResponse
  {
    $this->authorize('viewAny', Course::class);

    return $this->responder->success(
      data: $service->adminProgressDashboard(),
      message: 'Progress analytics retrieved.',
    );
  }
}
