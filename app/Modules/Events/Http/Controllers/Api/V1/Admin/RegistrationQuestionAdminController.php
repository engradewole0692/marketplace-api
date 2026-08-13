<?php

declare(strict_types=1);

namespace App\Modules\Events\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\ApiController;
use App\Modules\Events\Http\Requests\StoreRegistrationQuestionRequest;
use App\Modules\Events\Http\Resources\EventRegistrationQuestionResource;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Models\EventRegistrationQuestion;
use App\Modules\Events\Services\RegistrationFormConfigService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class RegistrationQuestionAdminController extends ApiController
{
  public function index(Event $event, RegistrationFormConfigService $service): JsonResponse
  {
    $this->authorize('update', $event);

    $questions = $service->listQuestions($event);

    return $this->responder->success(
      data: ['questions' => EventRegistrationQuestionResource::collection($questions)],
      message: 'Registration questions retrieved.',
    );
  }

  public function store(StoreRegistrationQuestionRequest $request, Event $event, RegistrationFormConfigService $service): JsonResponse
  {
    $this->authorize('update', $event);

    $question = $service->createQuestion($event, $request->validated());

    return $this->responder->success(
      data: ['question' => new EventRegistrationQuestionResource($question)],
      message: 'Registration question created.',
      status: 201,
    );
  }

  public function update(StoreRegistrationQuestionRequest $request, EventRegistrationQuestion $question, RegistrationFormConfigService $service): JsonResponse
  {
    $question->loadMissing('event');
    $this->authorize('update', $question->event);

    $updated = $service->updateQuestion($question, $request->validated());

    return $this->responder->success(
      data: ['question' => new EventRegistrationQuestionResource($updated)],
      message: 'Registration question updated.',
    );
  }

  public function destroy(EventRegistrationQuestion $question, RegistrationFormConfigService $service): JsonResponse
  {
    $question->loadMissing('event');
    $this->authorize('update', $question->event);

    $service->deleteQuestion($question);

    return $this->responder->success(data: null, message: 'Registration question deleted.');
  }

  public function reorder(Request $request, Event $event, RegistrationFormConfigService $service): JsonResponse
  {
    $this->authorize('update', $event);

    $request->validate([
      'question_ids' => ['required', 'array', 'min:1'],
      'question_ids.*' => ['required', 'string'],
    ]);

    $questions = $service->reorderQuestions($event, $request->input('question_ids'));

    return $this->responder->success(
      data: ['questions' => EventRegistrationQuestionResource::collection($questions)],
      message: 'Registration questions reordered.',
    );
  }
}
