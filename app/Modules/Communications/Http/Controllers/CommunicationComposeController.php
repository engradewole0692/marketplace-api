<?php

declare(strict_types=1);

namespace App\Modules\Communications\Http\Controllers;

use App\Http\Controllers\Api\V1\ApiController;
use App\Models\Person;
use App\Models\User;
use App\Modules\Communications\Models\BulkEmailJob;
use App\Modules\Communications\Models\CommunicationTemplate;
use App\Modules\Communications\Services\CommunicationActivityService;
use App\Modules\Communications\Services\CommunicationDispatchService;
use App\Modules\Communications\Models\CommunicationWhatsAppTemplate;
use App\Modules\Communications\Services\CommunicationConsentService;
use App\Modules\Communications\Services\OutboundMessageService;
use App\Modules\Communications\Services\ProviderConfigurationService;
use App\Modules\Communications\Support\DeliveryStatus;
use App\Modules\Communications\Services\RecipientAudienceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

final class CommunicationComposeController extends ApiController
{
    public function modules(RecipientAudienceService $audience): JsonResponse
    {
        $this->authorize('manage', BulkEmailJob::class);

        return $this->responder->success(
            data: ['modules' => $audience->modules(request()->user())],
            message: 'Communication audience modules retrieved.',
        );
    }

    public function search(Request $request, RecipientAudienceService $audience): JsonResponse
    {
        $this->authorize('manage', BulkEmailJob::class);
        $validated = $request->validate([
            'q' => ['required', 'string', 'min:2', 'max:120'],
        ]);

        return $this->responder->success(
            data: ['recipients' => $audience->search($validated['q'])],
            message: 'Contact search completed.',
        );
    }

    public function sendSingle(
        Request $request,
        CommunicationDispatchService $dispatch,
        OutboundMessageService $outbound,
    ): JsonResponse {
        $this->authorize('manage', BulkEmailJob::class);
        $validated = $request->validate([
            'channel' => ['required', 'in:email,sms,whatsapp,in_app'],
            'subject' => ['nullable', 'string', 'max:255'],
            'html_body' => ['nullable', 'string'],
            'text_body' => ['nullable', 'string'],
            'recipient_email' => ['nullable', 'email', 'max:255'],
            'recipient_phone' => ['nullable', 'string', 'max:40'],
            'recipient_name' => ['nullable', 'string', 'max:160'],
            'person_id' => ['nullable', 'string'],
            'user_id' => ['nullable', 'string'],
            'message_type' => ['nullable', 'in:promotional,transactional'],
        ]);
        $messageType = $validated['message_type'] ?? 'transactional';

        $person = ! empty($validated['person_id'])
            ? Person::query()->where('uuid', $validated['person_id'])->first()
            : null;
        $user = ! empty($validated['user_id'])
            ? User::query()->where('uuid', $validated['user_id'])->first()
            : null;

        $email = $validated['recipient_email'] ?? $person?->email ?? $user?->email;
        $phone = $validated['recipient_phone'] ?? $person?->phone ?? $user?->phone;
        $name = $validated['recipient_name'] ?? $person?->display_name ?? $user?->name ?? 'Recipient';
        $channel = $validated['channel'];
        $body = $validated['text_body'] ?? strip_tags((string) ($validated['html_body'] ?? ''));

        if ($channel === 'email') {
            if (! filled($email)) {
                return $this->responder->error('Recipient email is required.', 'VALIDATION_FAILED', 422);
            }
            if (config('mail.default') === 'log') {
                return $this->responder->error('Not sent: MAIL_MAILER is log, so email is written to the server log and never delivered.', 'MAILER_NOT_DELIVERING', 422);
            }
            $decision = app(CommunicationConsentService::class)->evaluate('email', (string) $email, $messageType);
            if (! $decision['allowed']) {
                return $this->responder->error('Not sent: this address is '.str_replace('_', ' ', (string) $decision['reason']).'.', 'CONSENT_EXCLUDED', 422);
            }
            $log = $dispatch->sendDirect(
                'communications.manual',
                'general',
                (string) $email,
                $name,
                [
                    'subject' => $validated['subject'] ?? 'Message',
                    'html_body' => $validated['html_body'] ?? ('<p>'.e($body).'</p>'),
                    'applicant_name' => $name,
                    'message_body' => $body,
                ],
                $person ?? $user,
                'manual:'.($person?->uuid ?? $user?->uuid ?? $email).':'.now()->timestamp,
            );

            $status = $log->status instanceof \BackedEnum ? $log->status->value : (string) $log->status;
            if ($status === 'failed') {
                return $this->responder->error(
                    'Email was not delivered: '.($log->error_message ?: 'provider rejected the message.'),
                    'MAIL_DELIVERY_FAILED',
                    422,
                    [],
                    ['log' => ['id' => $log->uuid, 'status' => $status]],
                );
            }

            return $this->responder->success(
                data: ['log' => ['id' => $log->uuid, 'status' => $status, 'channel' => 'email']],
                message: $status === 'sent' ? 'Email accepted by the mailer.' : 'Email queued. Delivery has not been confirmed.',
                status: 201,
            );
        }

        if ($channel === 'in_app') {
            return $this->responder->error(
                'Use the existing Notifications send endpoint for in-app messages.',
                'CHANNEL_NOT_SUPPORTED_HERE',
                422,
            );
        }

        if (! filled($phone)) {
            return $this->responder->error('Recipient phone is required for this channel.', 'VALIDATION_FAILED', 422);
        }

        $row = $outbound->send($channel, (string) $phone, $body, [
            'subject' => $validated['subject'] ?? null,
            'module' => 'general',
            'message_type' => $messageType,
        ], $request->user());

        if ($row->status !== DeliveryStatus::ACCEPTED) {
            return $this->responder->error(
                strtoupper($channel).' was not sent: '.($row->error_message ?: 'provider is not configured.'),
                $row->error_code === 'provider_not_configured' ? 'PROVIDER_NOT_CONFIGURED' : 'NOT_SENT',
                422,
                [],
                ['message' => ['id' => $row->uuid, 'status' => $row->status, 'error_code' => $row->error_code]],
            );
        }

        return $this->responder->success(
            data: ['message' => ['id' => $row->uuid, 'status' => $row->status, 'channel' => $channel]],
            message: strtoupper($channel).' was accepted by the provider.',
            status: 201,
        );
    }

    public function testChannel(Request $request, OutboundMessageService $outbound, CommunicationDispatchService $dispatch): JsonResponse
    {
        Gate::authorize('manage', CommunicationTemplate::class);
        $validated = $request->validate([
            'channel' => ['required', 'in:email,sms,whatsapp'],
            'to' => ['required', 'string', 'max:255'],
            'subject' => ['nullable', 'string', 'max:255'],
            'body' => ['nullable', 'string', 'max:2000'],
            'template_id' => ['nullable', 'string', 'max:64'],
        ]);

        $channel = $validated['channel'];
        $body = ($validated['body'] ?? null) ?: 'Marketplace Ministers test message.';
        $providers = app(ProviderConfigurationService::class);

        if ($channel === 'email') {
            $log = $dispatch->sendDirect(
                'communications.manual',
                'general',
                $validated['to'],
                'Test recipient',
                [
                    'subject' => $validated['subject'] ?? 'SMTP test',
                    'html_body' => '<p>'.e($body).'</p>',
                    'applicant_name' => 'Test recipient',
                    'message_body' => $body,
                ],
                null,
                'smtp-test:'.$validated['to'].':'.now()->timestamp,
            );
            $status = $log->status instanceof \BackedEnum ? $log->status->value : (string) $log->status;
            if ($status === 'failed') {
                $providers->recordTestResult('email', false, 'Test email failed: '.($log->error_message ?: 'rejected'));

                return $this->responder->error(
                    'Test email failed: '.($log->error_message ?: 'provider rejected the message.'),
                    'MAIL_DELIVERY_FAILED',
                    422,
                );
            }
            if ($status === 'sent') {
                $providers->recordTestResult('email', true, 'Test email accepted by the mail server.', $log->uuid);
            }

            return $this->responder->success(
                data: ['status' => $status, 'channel' => 'email'],
                message: $status === 'sent'
                    ? 'Test email was accepted by the mailer. Check the inbox and communication log.'
                    : 'Test email queued. Delivery has not been confirmed.',
            );
        }

        $template = null;
        if ($channel === 'whatsapp' && ! empty($validated['template_id'])) {
            $model = CommunicationWhatsAppTemplate::query()->where('uuid', $validated['template_id'])->first();
            if ($model === null || ! $model->isUsable()) {
                return $this->responder->error('Select an approved, active WhatsApp template.', 'TEMPLATE_REQUIRED', 422);
            }
            $template = [
                'name' => $model->name,
                'language' => $model->language,
                'provider_template_id' => $model->provider_template_id,
                'parameters' => array_map(fn () => 'Test', (array) ($model->parameters ?? [])),
            ];
        }

        $row = $outbound->send($channel, $validated['to'], $body, [
            'subject' => $validated['subject'] ?? 'Provider test',
            'module' => 'communications',
            'message_type' => 'transactional',
            'whatsapp_template' => $template,
            'skip_consent_check' => true,
        ], $request->user());

        if ($row->status !== DeliveryStatus::ACCEPTED) {
            if ($row->error_code !== 'provider_not_configured') {
                $providers->recordTestResult($channel, false, (string) ($row->error_message ?: 'Provider rejected the test.'));
            }

            return $this->responder->error(
                'Test '.strtoupper($channel).' was not sent: '.($row->error_message ?: 'provider is not configured.'),
                $row->error_code === 'provider_not_configured' ? 'PROVIDER_NOT_CONFIGURED' : 'PROVIDER_REJECTED',
                422,
                [],
                ['status' => $row->status, 'channel' => $channel, 'error_code' => $row->error_code],
            );
        }

        $providers->recordTestResult($channel, true, 'Test accepted by '.$row->provider.'.', $row->provider_message_id);

        return $this->responder->success(
            data: ['status' => $row->status, 'channel' => $channel, 'id' => $row->uuid, 'provider_message_id' => $row->provider_message_id],
            message: 'Test '.strtoupper($channel).' was accepted by the provider. Delivery updates arrive via the provider callback.',
        );
    }

    public function activity(Request $request, CommunicationActivityService $activity): JsonResponse
    {
        Gate::authorize('manage', CommunicationTemplate::class);
        $paginator = $activity->paginate($request->query(), (int) $request->query('per_page', 25));

        return $this->responder->success(
            data: [
                'items' => $paginator->items(),
                'meta' => [
                    'current_page' => $paginator->currentPage(),
                    'last_page' => $paginator->lastPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                ],
            ],
            message: 'Communication activity retrieved.',
        );
    }
}
