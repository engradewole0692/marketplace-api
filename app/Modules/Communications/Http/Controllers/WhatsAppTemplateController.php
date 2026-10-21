<?php

declare(strict_types=1);

namespace App\Modules\Communications\Http\Controllers;

use App\Http\Controllers\Api\V1\ApiController;
use App\Modules\Cms\Notifications\MetaWhatsAppNotifier;
use App\Modules\Communications\Models\CommunicationTemplate;
use App\Modules\Communications\Models\CommunicationWhatsAppTemplate;
use App\Modules\Communications\Services\CampaignMessageRenderer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Registry of provider-approved WhatsApp templates. Meta templates are synced from the WhatsApp Business
 * Account; Twilio templates are registered with their Content SID after approval in Twilio.
 */
final class WhatsAppTemplateController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('manage', CommunicationTemplate::class);
        $templates = CommunicationWhatsAppTemplate::query()
            ->when($request->boolean('usable'), fn ($q) => $q->where('status', 'approved')->where('is_active', true))
            ->orderBy('name')
            ->get()
            ->map(fn (CommunicationWhatsAppTemplate $t) => $this->transform($t));

        return $this->responder->success(
            data: ['templates' => $templates, 'variables' => CampaignMessageRenderer::VARIABLES],
            message: 'WhatsApp templates retrieved.',
        );
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('manage', CommunicationTemplate::class);
        $validated = $this->validated($request);
        $template = CommunicationWhatsAppTemplate::query()->create([...$validated, 'created_by' => $request->user()?->id]);

        return $this->responder->success(data: ['template' => $this->transform($template)], message: 'WhatsApp template registered.', status: 201);
    }

    public function update(Request $request, CommunicationWhatsAppTemplate $template): JsonResponse
    {
        Gate::authorize('manage', CommunicationTemplate::class);
        $template->fill($this->validated($request, $template))->save();

        return $this->responder->success(data: ['template' => $this->transform($template)], message: 'WhatsApp template updated.');
    }

    public function destroy(CommunicationWhatsAppTemplate $template): JsonResponse
    {
        Gate::authorize('manage', CommunicationTemplate::class);
        $template->forceFill(['is_active' => false])->save();

        return $this->responder->success(message: 'WhatsApp template deactivated.');
    }

    public function sync(MetaWhatsAppNotifier $meta): JsonResponse
    {
        Gate::authorize('manage', CommunicationTemplate::class);
        if (config('communications.whatsapp_provider') !== 'meta') {
            return $this->responder->error('Template sync is available for WHATSAPP_PROVIDER=meta. Register Twilio templates manually with their Content SID.', 'SYNC_UNAVAILABLE', 422);
        }
        $result = $meta->fetchTemplates();
        if (! $result['ok']) {
            return $this->responder->error($result['message'], 'SYNC_FAILED', 422);
        }

        $synced = 0;
        foreach ($result['templates'] ?? [] as $remote) {
            $body = collect($remote['components'] ?? [])->firstWhere('type', 'BODY');
            $text = (string) ($body['text'] ?? '');
            preg_match_all('/\{\{(\d+)\}\}/', $text, $matches);
            $existing = CommunicationWhatsAppTemplate::query()
                ->where('provider', 'meta')->where('name', (string) $remote['name'])->where('language', (string) $remote['language'])
                ->first();
            $parameters = $existing?->parameters ?: array_map(
                fn (string $n): array => ['key' => $n, 'label' => 'Parameter '.$n, 'source' => 'fixed'],
                array_values(array_unique($matches[1] ?? [])),
            );
            CommunicationWhatsAppTemplate::query()->updateOrCreate(
                ['provider' => 'meta', 'name' => (string) $remote['name'], 'language' => (string) $remote['language']],
                [
                    'category' => strtolower((string) ($remote['category'] ?? 'utility')),
                    'status' => strtolower((string) ($remote['status'] ?? 'pending')),
                    'body_preview' => mb_substr($text, 0, 2000),
                    'parameters' => $parameters,
                    'status_synced_at' => now(),
                ],
            );
            $synced++;
        }

        return $this->responder->success(data: ['synced' => $synced], message: "{$synced} templates synced from Meta.");
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?CommunicationWhatsAppTemplate $template = null): array
    {
        $required = $template === null ? 'required' : 'sometimes';

        return $request->validate([
            'provider' => [$required, Rule::in(['meta', 'twilio'])],
            'name' => [$required, 'string', 'max:191', 'regex:/^[a-z0-9_]+$/'],
            'language' => [$required, 'string', 'max:20'],
            'category' => [$required, Rule::in(['marketing', 'utility', 'authentication'])],
            'status' => [$required, Rule::in(['approved', 'pending', 'rejected', 'paused', 'disabled'])],
            'body_preview' => ['nullable', 'string', 'max:2000'],
            'provider_template_id' => [
                Rule::requiredIf(fn (): bool => $request->input('provider', $template?->provider) === 'twilio' && ! filled($template?->provider_template_id)),
                'nullable', 'string', 'max:191', 'regex:/^HX[0-9a-fA-F]{32}$/',
            ],
            'parameters' => ['nullable', 'array', 'max:20'],
            'parameters.*.key' => ['required_with:parameters', 'string', 'max:40'],
            'parameters.*.label' => ['nullable', 'string', 'max:120'],
            'parameters.*.source' => ['nullable', 'string', 'max:60'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function transform(CommunicationWhatsAppTemplate $t): array
    {
        return [
            'id' => $t->uuid,
            'provider' => $t->provider,
            'name' => $t->name,
            'language' => $t->language,
            'category' => $t->category,
            'status' => $t->status,
            'body_preview' => $t->body_preview,
            'parameters' => $t->parameters ?? [],
            'provider_template_id' => $t->provider_template_id,
            'is_active' => $t->is_active,
            'usable' => $t->isUsable(),
            'status_synced_at' => $t->status_synced_at?->toIso8601String(),
        ];
    }
}
