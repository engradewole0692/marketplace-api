<?php

declare(strict_types=1);

namespace App\Modules\Communications\Http\Controllers;

use App\Http\Controllers\Api\V1\ApiController;
use App\Modules\Communications\Models\CommunicationTemplate;
use App\Modules\Communications\Services\ProviderConfigurationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

final class CommunicationProviderController extends ApiController
{
    public function index(ProviderConfigurationService $providers): JsonResponse
    {
        Gate::authorize('manage', CommunicationTemplate::class);

        return $this->responder->success(data: ['providers' => $providers->snapshot()], message: 'Provider configuration retrieved.');
    }

    public function check(string $channel, ProviderConfigurationService $providers): JsonResponse
    {
        Gate::authorize('manage', CommunicationTemplate::class);
        abort_unless(in_array($channel, ['email', 'sms', 'whatsapp'], true), 404);

        $result = $providers->checkCredentials($channel);

        return $this->responder->success(
            data: ['result' => $result, 'provider' => $providers->channel($channel)],
            message: $result['ok']
                ? 'Credentials accepted. Send a test message to mark this channel verified.'
                : 'Credential check failed: '.$result['message'],
        );
    }
}
