<?php

declare(strict_types=1);

namespace App\Modules\Events\Http\Controllers\Api\V1\Public;

use App\Http\Controllers\Api\V1\ApiController;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Services\RegistrationFormConfigService;
use App\Modules\Events\Support\PublicEventAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class PublicRegistrationFormController extends ApiController
{
  public function show(Request $request, Event $event, RegistrationFormConfigService $service): JsonResponse
  {
    PublicEventAccess::ensure($event);

    $context = (string) $request->query('context', RegistrationFormConfigService::CONTEXT_PUBLIC);
    if ($context !== RegistrationFormConfigService::CONTEXT_PUBLIC) {
      $context = RegistrationFormConfigService::CONTEXT_PUBLIC;
    }

    return $this->responder->success(
      data: ['form' => $service->buildFormSchema($event, $context)],
      message: 'Public registration form schema retrieved.',
    );
  }
}
