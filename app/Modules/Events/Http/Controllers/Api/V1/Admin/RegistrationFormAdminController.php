<?php

declare(strict_types=1);

namespace App\Modules\Events\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\ApiController;
use App\Modules\Events\Http\Resources\EventRegistrationFieldSettingResource;
use App\Modules\Events\Http\Resources\EventRegistrationQuestionResource;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Services\RegistrationFormConfigService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class RegistrationFormAdminController extends ApiController
{
  public function show(Request $request, Event $event, RegistrationFormConfigService $service): JsonResponse
  {
    $this->authorize('view', $event);

    $context = (string) $request->query('context', RegistrationFormConfigService::CONTEXT_QUICK);
    $schema = $service->buildFormSchema($event, $context);

    return $this->responder->success(
      data: [
        'form' => $schema,
        'settings' => EventRegistrationFieldSettingResource::collection($service->listFieldSettings($event)),
        'questions' => EventRegistrationQuestionResource::collection($service->listQuestions($event)),
      ],
      message: 'Registration form schema retrieved.',
    );
  }
}
