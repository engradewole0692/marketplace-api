<?php

declare(strict_types=1);

namespace App\Modules\Communications\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\ApiController;
use App\Modules\Communications\Http\Resources\CommunicationEmailLogResource;
use App\Modules\Communications\Http\Resources\CommunicationRouteResource;
use App\Modules\Communications\Http\Resources\CommunicationSettingResource;
use App\Modules\Communications\Http\Resources\CommunicationTemplateResource;
use App\Modules\Communications\Models\CommunicationEmailLog;
use App\Modules\Communications\Models\CommunicationRoute;
use App\Modules\Communications\Models\CommunicationTemplate;
use App\Modules\Communications\Services\CommunicationDispatchService;
use App\Modules\Communications\Services\CommunicationRouteService;
use App\Modules\Communications\Services\CommunicationSettingsService;
use App\Modules\Communications\Services\CommunicationTemplateService;
use App\Support\Api\PaginatedResponseBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

final class CommunicationAdminController extends ApiController
{
  public function settingsShow(CommunicationSettingsService $settings): JsonResponse
  {
    Gate::authorize('manage', CommunicationTemplate::class);
    $model = $settings->get();

    return $this->responder->success(
      data: ['settings' => new CommunicationSettingResource($model)],
      message: 'Communication settings retrieved.',
    );
  }

  public function settingsUpdate(Request $request, CommunicationSettingsService $settings): JsonResponse
  {
    Gate::authorize('manage', CommunicationTemplate::class);
    $validated = $request->validate([
      'ministry_email' => ['nullable', 'email', 'max:255'],
      'reply_to_email' => ['nullable', 'email', 'max:255'],
      'reply_to_name' => ['nullable', 'string', 'max:255'],
      'from_name' => ['nullable', 'string', 'max:255'],
      'branding' => ['nullable', 'array'],
    ]);

    $model = $settings->update($validated);

    return $this->responder->success(
      data: ['settings' => new CommunicationSettingResource($model)],
      message: 'Communication settings updated.',
    );
  }

  public function routesIndex(Request $request, CommunicationRouteService $routes): JsonResponse
  {
    Gate::authorize('manage', CommunicationTemplate::class);

    return $this->responder->success(
      data: PaginatedResponseBuilder::fromPaginator($routes->paginate($request->query()), CommunicationRouteResource::class),
      message: 'Communication routes retrieved.',
    );
  }

  public function routesStore(Request $request, CommunicationRouteService $routes): JsonResponse
  {
    Gate::authorize('manage', CommunicationTemplate::class);
    $validated = $this->validatedRoute($request);
    $route = $routes->create($validated, $request->user());

    return $this->responder->success(
      data: ['route' => new CommunicationRouteResource($route->load('user'))],
      message: 'Communication route created.',
      status: 201,
    );
  }

  public function routesUpdate(Request $request, CommunicationRoute $route, CommunicationRouteService $routes): JsonResponse
  {
    Gate::authorize('manage', CommunicationTemplate::class);
    $route = $routes->update($route, $this->validatedRoute($request, true), $request->user());

    return $this->responder->success(
      data: ['route' => new CommunicationRouteResource($route)],
      message: 'Communication route updated.',
    );
  }

  public function routesDestroy(CommunicationRoute $route, CommunicationRouteService $routes): JsonResponse
  {
    Gate::authorize('manage', CommunicationTemplate::class);
    $routes->delete($route);

    return $this->responder->success(message: 'Communication route deleted.');
  }

  public function templatesIndex(Request $request, CommunicationTemplateService $templates): JsonResponse
  {
    Gate::authorize('manage', CommunicationTemplate::class);

    return $this->responder->success(
      data: PaginatedResponseBuilder::fromPaginator($templates->paginate($request->query()), CommunicationTemplateResource::class),
      message: 'Communication templates retrieved.',
    );
  }

  public function templatesShow(CommunicationTemplate $template): JsonResponse
  {
    Gate::authorize('manage', CommunicationTemplate::class);

    return $this->responder->success(
      data: ['template' => new CommunicationTemplateResource($template)],
      message: 'Communication template retrieved.',
    );
  }

  public function templatesStore(Request $request, CommunicationTemplateService $templates): JsonResponse
  {
    Gate::authorize('manage', CommunicationTemplate::class);
    $template = $templates->create($this->validatedTemplate($request), $request->user());

    return $this->responder->success(
      data: ['template' => new CommunicationTemplateResource($template)],
      message: 'Communication template created.',
      status: 201,
    );
  }

  public function templatesUpdate(Request $request, CommunicationTemplate $template, CommunicationTemplateService $templates): JsonResponse
  {
    Gate::authorize('manage', CommunicationTemplate::class);
    $template = $templates->update($template, $this->validatedTemplate($request, true), $request->user());

    return $this->responder->success(
      data: ['template' => new CommunicationTemplateResource($template)],
      message: 'Communication template updated.',
    );
  }

  public function templatesDuplicate(CommunicationTemplate $template, Request $request, CommunicationTemplateService $templates): JsonResponse
  {
    Gate::authorize('manage', CommunicationTemplate::class);
    $copy = $templates->duplicate($template, $request->user());

    return $this->responder->success(
      data: ['template' => new CommunicationTemplateResource($copy)],
      message: 'Communication template duplicated.',
      status: 201,
    );
  }

  public function templatesReset(CommunicationTemplate $template, CommunicationTemplateService $templates): JsonResponse
  {
    Gate::authorize('manage', CommunicationTemplate::class);
    $template = $templates->resetSystemTemplate($template);

    return $this->responder->success(
      data: ['template' => new CommunicationTemplateResource($template)],
      message: 'Communication template reset.',
    );
  }

  public function templatesPreview(Request $request, CommunicationTemplate $template, CommunicationTemplateService $templates): JsonResponse
  {
    Gate::authorize('manage', CommunicationTemplate::class);
    $variables = $request->validate(['variables' => ['nullable', 'array']])['variables'] ?? [];
    $preview = $templates->preview($template, $variables);

    return $this->responder->success(
      data: ['preview' => $preview],
      message: 'Template preview generated.',
    );
  }

  public function templatesTestSend(
    Request $request,
    CommunicationTemplate $template,
    CommunicationDispatchService $dispatch,
  ): JsonResponse {
    Gate::authorize('manage', CommunicationTemplate::class);
    $validated = $request->validate([
      'recipient_email' => ['required', 'email', 'max:255'],
      'variables' => ['nullable', 'array'],
    ]);

    $log = $dispatch->sendTestEmail(
      $template,
      $validated['recipient_email'],
      $validated['variables'] ?? [],
    );

    return $this->responder->success(
      data: ['log' => new CommunicationEmailLogResource($log)],
      message: 'Test email dispatched.',
    );
  }

  public function logsIndex(Request $request): JsonResponse
  {
    Gate::authorize('manage', CommunicationTemplate::class);

    $query = CommunicationEmailLog::query()->with('template')->latest();
    if ($request->filled('status')) {
      $query->where('status', $request->query('status'));
    }
    if ($request->filled('section')) {
      $query->where('section', $request->query('section'));
    }
    if ($request->filled('recipient')) {
      $query->where('recipient_email', 'like', '%'.$request->query('recipient').'%');
    }
    if ($request->filled('event_key')) {
      $query->where('event_key', $request->query('event_key'));
    }

    $paginator = $query->paginate(min(100, max(1, (int) $request->query('per_page', 25))));

    return $this->responder->success(
      data: PaginatedResponseBuilder::fromPaginator($paginator, CommunicationEmailLogResource::class),
      message: 'Email logs retrieved.',
    );
  }

  public function logsShow(CommunicationEmailLog $log): JsonResponse
  {
    Gate::authorize('manage', CommunicationTemplate::class);
    $log->load('template');

    return $this->responder->success(
      data: ['log' => new CommunicationEmailLogResource($log)],
      message: 'Email log retrieved.',
    );
  }

  /** @return array<string, mixed> */
  private function validatedRoute(Request $request, bool $partial = false): array
  {
    $rules = [
      'section' => [$partial ? 'sometimes' : 'required', 'string', 'max:64'],
      'event_key' => ['nullable', 'string', 'max:128'],
      'label' => [$partial ? 'sometimes' : 'required', 'string', 'max:255'],
      'recipient_role' => ['sometimes', 'string', 'in:to,cc,bcc'],
      'recipient_type' => [$partial ? 'sometimes' : 'required', 'string', 'max:32'],
      'email' => ['nullable', 'email', 'max:255'],
      'user_id' => ['nullable', 'string'],
      'sort_order' => ['nullable', 'integer', 'min:0'],
      'include_section_fallback' => ['sometimes', 'boolean'],
      'include_ministry_fallback' => ['sometimes', 'boolean'],
      'is_active' => ['sometimes', 'boolean'],
      'metadata' => ['nullable', 'array'],
    ];

    return $request->validate($rules);
  }

  /** @return array<string, mixed> */
  private function validatedTemplate(Request $request, bool $partial = false): array
  {
    return $request->validate([
      'slug' => ['nullable', 'string', 'max:255'],
      'name' => [$partial ? 'sometimes' : 'required', 'string', 'max:255'],
      'section' => [$partial ? 'sometimes' : 'required', 'string', 'max:64'],
      'event_key' => [$partial ? 'sometimes' : 'required', 'string', 'max:128'],
      'description' => ['nullable', 'string'],
      'subject' => [$partial ? 'sometimes' : 'required', 'string', 'max:255'],
      'html_body' => [$partial ? 'sometimes' : 'required', 'string'],
      'text_body' => ['nullable', 'string'],
      'available_variables' => ['nullable', 'array'],
      'sample_variables' => ['nullable', 'array'],
      'is_active' => ['sometimes', 'boolean'],
    ]);
  }
}
