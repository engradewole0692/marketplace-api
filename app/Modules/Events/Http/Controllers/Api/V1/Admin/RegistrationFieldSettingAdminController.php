<?php

declare(strict_types=1);

namespace App\Modules\Events\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\ApiController;
use App\Modules\Events\Http\Requests\StoreRegistrationQuestionRequest;
use App\Modules\Events\Http\Requests\SyncRegistrationFieldSettingsRequest;
use App\Modules\Events\Http\Resources\EventRegistrationFieldSettingResource;
use App\Modules\Events\Http\Resources\EventRegistrationQuestionResource;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Models\EventRegistrationQuestion;
use App\Modules\Events\Services\RegistrationFormConfigService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class RegistrationFieldSettingAdminController extends ApiController
{
  public function index(Event $event, RegistrationFormConfigService $service): JsonResponse
  {
    $this->authorize('update', $event);

    $settings = $service->listFieldSettings($event);

    return $this->responder->success(
      data: ['settings' => EventRegistrationFieldSettingResource::collection($settings)],
      message: 'Registration field settings retrieved.',
    );
  }

  public function sync(SyncRegistrationFieldSettingsRequest $request, Event $event, RegistrationFormConfigService $service): JsonResponse
  {
    $this->authorize('update', $event);

    $settings = $service->syncFieldSettings($event, $request->validated('settings'));

    return $this->responder->success(
      data: ['settings' => EventRegistrationFieldSettingResource::collection($settings)],
      message: 'Registration field settings updated.',
    );
  }
}
