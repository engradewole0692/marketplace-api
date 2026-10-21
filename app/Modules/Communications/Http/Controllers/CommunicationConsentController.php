<?php

declare(strict_types=1);

namespace App\Modules\Communications\Http\Controllers;

use App\Http\Controllers\Api\V1\ApiController;
use App\Modules\Communications\Models\BulkEmailJob;
use App\Modules\Communications\Models\CommunicationConsent;
use App\Modules\Communications\Services\CommunicationConsentService;
use App\Modules\Communications\Services\RecipientAudienceService;
use App\Modules\Communications\Support\PhoneNumberService;
use App\Support\Api\PaginatedResponseBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class CommunicationConsentController extends ApiController
{
    public function index(Request $request, RecipientAudienceService $audience): JsonResponse
    {
        $this->authorize('manage', BulkEmailJob::class);
        $paginator = CommunicationConsent::query()
            ->when($request->query('channel'), fn ($q, $c) => $q->where('channel', $c))
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($request->query('q'), function ($q, $term) {
                $digits = preg_replace('/\D+/', '', (string) $term) ?? '';
                $q->where(function ($w) use ($term, $digits): void {
                    $w->where('destination', 'like', '%'.strtolower((string) $term).'%');
                    if (strlen($digits) >= 4) {
                        $w->orWhere('destination', 'like', '%'.$digits.'%');
                    }
                });
            })
            ->latest('updated_at')
            ->paginate(min(100, max(1, (int) $request->query('per_page', 25))));

        return $this->responder->success(
            data: PaginatedResponseBuilder::fromPaginator($paginator, fn (CommunicationConsent $c) => $this->transform($c, $audience)),
            message: 'Consent records retrieved.',
        );
    }

    public function store(Request $request, CommunicationConsentService $consent, PhoneNumberService $phones, RecipientAudienceService $audience): JsonResponse
    {
        $this->authorize('manage', BulkEmailJob::class);
        $validated = $request->validate([
            'channel' => ['required', Rule::in(['email', 'sms', 'whatsapp'])],
            'destination' => ['required', 'string', 'max:191'],
            'status' => ['required', Rule::in([CommunicationConsent::OPTED_IN, CommunicationConsent::OPTED_OUT, CommunicationConsent::SUPPRESSED])],
            'purpose' => ['nullable', Rule::in(['promotional', 'all'])],
            'source' => ['required', 'string', 'max:60'],
            'reason' => ['nullable', 'string', 'max:255'],
            'evidence' => ['nullable', 'string', 'max:500'],
        ]);

        $destination = $validated['destination'];
        if ($validated['channel'] === 'email') {
            if (! filter_var($destination, FILTER_VALIDATE_EMAIL)) {
                return $this->responder->error('Enter a valid email address.', 'VALIDATION_FAILED', 422);
            }
        } else {
            $e164 = $phones->e164($destination);
            if ($e164 === null) {
                return $this->responder->error('Enter a valid phone number in international format (e.g. +2348031234567).', 'VALIDATION_FAILED', 422);
            }
            $destination = $e164;
        }
        if ($validated['status'] === CommunicationConsent::OPTED_IN && empty($validated['evidence'])) {
            return $this->responder->error('Record how and when the person opted in (evidence) before marking them opted in.', 'VALIDATION_FAILED', 422);
        }

        $row = $consent->record(
            $validated['channel'],
            $destination,
            $validated['status'],
            $validated['purpose'] ?? ($validated['status'] === CommunicationConsent::OPTED_IN ? 'promotional' : 'all'),
            $validated['source'],
            $validated['reason'] ?? null,
            $request->user(),
            null,
            null,
            array_filter(['evidence' => $validated['evidence'] ?? null]),
        );

        return $this->responder->success(data: ['consent' => $this->transform($row, $audience)], message: 'Consent recorded.', status: 201);
    }

    public function history(CommunicationConsent $consent, RecipientAudienceService $audience): JsonResponse
    {
        $this->authorize('manage', BulkEmailJob::class);

        return $this->responder->success(
            data: [
                'consent' => $this->transform($consent, $audience),
                'events' => $consent->events()->latest('id')->limit(100)->get()->map(fn ($e) => [
                    'action' => $e->action,
                    'from_status' => $e->from_status,
                    'to_status' => $e->to_status,
                    'source' => $e->source,
                    'reason' => $e->reason,
                    'actor_user_id' => $e->actor_user_id,
                    'at' => $e->created_at?->toIso8601String(),
                ]),
            ],
            message: 'Consent history retrieved.',
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function transform(CommunicationConsent $c, RecipientAudienceService $audience): array
    {
        return [
            'id' => $c->uuid,
            'channel' => $c->channel,
            'destination' => $audience->mask($c->channel, $c->destination),
            'purpose' => $c->purpose,
            'status' => $c->status,
            'source' => $c->source,
            'reason' => $c->reason,
            'opted_in_at' => $c->opted_in_at?->toIso8601String(),
            'opted_out_at' => $c->opted_out_at?->toIso8601String(),
            'suppressed_at' => $c->suppressed_at?->toIso8601String(),
            'updated_at' => $c->updated_at?->toIso8601String(),
        ];
    }
}
