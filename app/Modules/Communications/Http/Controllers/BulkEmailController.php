<?php

declare(strict_types=1);

namespace App\Modules\Communications\Http\Controllers;

use App\Http\Controllers\Api\V1\ApiController;
use App\Modules\Communications\Mail\CommunicationMailable;
use App\Modules\Communications\Models\BulkEmailJob;
use App\Modules\Communications\Models\BulkEmailRecipient;
use App\Modules\Communications\Services\BulkEmailService;
use App\Modules\Communications\Services\CampaignDispatchService;
use App\Modules\Communications\Services\CampaignManualRecipientService;
use App\Modules\Communications\Services\CampaignMessageRenderer;
use App\Modules\Communications\Services\OutboundMessageService;
use App\Modules\Communications\Services\RecipientAudienceService;
use App\Modules\Communications\Support\DeliveryStatus;
use App\Modules\Communications\Support\SmsSegmentCalculator;
use App\Support\Api\PaginatedResponseBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Communication campaigns (email, SMS, WhatsApp). Route names keep the historical "bulk-email" prefix.
 */
final class BulkEmailController extends ApiController
{
  private const FILTER_RULES = [
    'recipient_filters' => ['required', 'array'],
    'recipient_filters.module' => ['nullable', 'string', 'max:40'],
    'recipient_filters.audience' => ['nullable', 'string', 'in:all,visitors,members,staff,admins'],
    'recipient_filters.country_id' => ['nullable', 'integer'],
    'recipient_filters.role_slug' => ['nullable', 'string', 'max:80'],
    'recipient_filters.ministry_id' => ['nullable', 'integer'],
    'recipient_filters.event_id' => ['nullable', 'string', 'max:64'],
    'recipient_filters.event_session_id' => ['nullable', 'string', 'max:64'],
    'recipient_filters.gender' => ['nullable', 'string', 'max:40'],
    'recipient_filters.category' => ['nullable', 'string', 'max:80'],
    'recipient_filters.accommodation' => ['nullable', 'string', 'in:yes,no,true,false,1,0'],
    'recipient_filters.transport' => ['nullable', 'string', 'in:yes,no,true,false,1,0'],
    'recipient_filters.payment' => ['nullable', 'string', 'in:paid,pending'],
    'recipient_filters.attendance' => ['nullable', 'string', 'max:40'],
    'recipient_filters.country' => ['nullable', 'string', 'max:120'],
    'recipient_filters.course_id' => ['nullable', 'integer'],
    'recipient_filters.registration_status' => ['nullable', 'string', 'max:40'],
    'recipient_filters.state_region' => ['nullable', 'string', 'max:120'],
    'recipient_filters.city' => ['nullable', 'string', 'max:120'],
    'recipient_filters.occupancy_type' => ['nullable', 'string', 'in:private,shared'],
    'recipient_filters.seat_counting' => ['nullable', 'string', 'in:yes,no'],
    'recipient_filters.school_id' => ['nullable', 'integer'],
    'recipient_filters.program_module_id' => ['nullable', 'integer'],
    'recipient_filters.lesson_id' => ['nullable', 'integer'],
    'recipient_filters.enrollment_status' => ['nullable', 'string', 'max:40'],
    'recipient_filters.assignment_status' => ['nullable', 'string', 'max:40'],
    'recipient_filters.learner_type' => ['nullable', 'string', 'max:20'],
    'recipient_filters.status' => ['nullable', 'string', 'max:40'],
    'recipient_filters.counsellor_id' => ['nullable', 'integer'],
    'recipient_filters.category_id' => ['nullable', 'integer'],
    'recipient_filters.assigned' => ['nullable', 'string', 'in:yes,no'],
    'recipient_filters.client_type' => ['nullable', 'string', 'max:40'],
    'recipient_filters.form_type' => ['nullable', 'string', 'max:40'],
    'recipient_filters.approval_status' => ['nullable', 'string', 'max:40'],
    'recipient_filters.interview_status' => ['nullable', 'string', 'max:40'],
    'recipient_filters.submitted_from' => ['nullable', 'date'],
    'recipient_filters.submitted_to' => ['nullable', 'date'],
    'recipient_filters.registered_from' => ['nullable', 'date'],
    'recipient_filters.registered_to' => ['nullable', 'date'],
    'recipient_filters.channel' => ['nullable', 'in:email,sms,whatsapp'],
    'channel' => ['nullable', 'in:email,sms,whatsapp'],
    'channels' => ['nullable', 'array', 'min:1'],
    'channels.*' => ['in:email,sms,whatsapp'],
    'message_type' => ['nullable', 'in:promotional,transactional'],
  ];

  private const CONTENT_RULES = [
    'subject' => ['required', 'string', 'max:255'],
    'html_body' => ['nullable', 'string', 'max:200000'],
    'text_body' => ['nullable', 'string', 'max:5000'],
    'from_name' => ['nullable', 'string', 'max:100'],
    'from_email' => ['nullable', 'email', 'max:255'],
    'whatsapp_template' => ['nullable', 'array'],
    'whatsapp_template.template_id' => ['nullable', 'string', 'max:64'],
    'whatsapp_template.values' => ['nullable', 'array'],
    'whatsapp_template.values.*' => ['nullable', 'string', 'max:500'],
  ];

  public function index(Request $request, BulkEmailService $service): JsonResponse
  {
    $this->authorize('manage', BulkEmailJob::class);

    $paginator = BulkEmailJob::query()
      ->with('creator:id,uuid,name,email')
      ->when($request->query('status'), fn ($q, $status) => $q->where('status', $status))
      ->latest()
      ->paginate(min(100, max(1, (int) $request->query('per_page', 20))));

    return $this->responder->success(
      data: PaginatedResponseBuilder::fromPaginator($paginator, fn ($j) => $this->transform($j)),
      message: 'Campaigns retrieved.',
    );
  }

  public function estimate(Request $request, RecipientAudienceService $audience, CampaignManualRecipientService $manual): JsonResponse
  {
    $this->authorize('manage', BulkEmailJob::class);
    $validated = $request->validate([
      ...self::FILTER_RULES,
      'text_body' => ['nullable', 'string', 'max:5000'],
      'campaign_id' => ['nullable', 'uuid'],
    ]);
    $filters = $validated['recipient_filters'];
    $this->authorizeModule($request, $audience, $filters);

    $channels = $validated['channels'] ?? [$validated['channel'] ?? $filters['channel'] ?? 'email'];
    $preview = $audience->preview($filters, $channels, $validated['message_type'] ?? 'promotional', $manual->emailsFor($this->draftFor($validated['campaign_id'] ?? null)));
    $preview['estimated_count'] = $preview['total_messages'];
    $preview['costs'] = $this->costEstimate($preview, $validated['text_body'] ?? null);
    $preview['confirmation_threshold'] = (int) config('communications.campaigns.confirmation_threshold', 100);

    return $this->responder->success(data: $preview, message: 'Recipient preview calculated.');
  }

  public function store(Request $request, BulkEmailService $service, RecipientAudienceService $audience): JsonResponse
  {
    $this->authorize('manage', BulkEmailJob::class);
    $validated = $request->validate([
      ...self::FILTER_RULES,
      ...self::CONTENT_RULES,
      'action' => ['nullable', 'in:draft,send,schedule'],
      'scheduled_at' => ['nullable', 'date'],
      'confirm_total' => ['nullable', 'integer'],
    ]);
    $this->authorizeModule($request, $audience, $validated['recipient_filters']);

    $job = $service->create($validated, $request->user());

    return $this->responder->success(
      data: ['job' => $this->transform($job->fresh('creator') ?? $job)],
      message: match ($job->status) {
        BulkEmailJob::STATUS_DRAFT => 'Campaign saved as draft.',
        BulkEmailJob::STATUS_SCHEDULED => 'Campaign scheduled.',
        default => 'Campaign queued. Messages are sent by the queue worker; delivery is tracked per recipient.',
      },
      status: 201,
    );
  }

  public function update(Request $request, BulkEmailJob $bulkEmailJob, BulkEmailService $service, RecipientAudienceService $audience): JsonResponse
  {
    $this->authorize('manage', BulkEmailJob::class);
    $rules = array_map(fn (array $r) => array_map(fn ($x) => $x === 'required' ? 'sometimes' : $x, $r), [...self::FILTER_RULES, ...self::CONTENT_RULES]);
    $validated = $request->validate($rules);
    $this->authorizeModule($request, $audience, $validated['recipient_filters'] ?? $bulkEmailJob->recipient_filters ?? []);

    return $this->responder->success(
      data: ['job' => $this->transform($service->updateDraft($bulkEmailJob, $validated))],
      message: 'Draft updated.',
    );
  }

  public function send(Request $request, BulkEmailJob $bulkEmailJob, BulkEmailService $service, RecipientAudienceService $audience): JsonResponse
  {
    $this->authorize('manage', BulkEmailJob::class);
    $validated = $request->validate([
      'action' => ['nullable', 'in:send,schedule'],
      'scheduled_at' => ['nullable', 'date'],
      'confirm_total' => ['nullable', 'integer'],
    ]);
    $this->authorizeModule($request, $audience, $bulkEmailJob->recipient_filters ?? []);

    $job = $service->launchDraft($bulkEmailJob, $validated);

    return $this->responder->success(
      data: ['job' => $this->transform($job)],
      message: $job->status === BulkEmailJob::STATUS_SCHEDULED ? 'Campaign scheduled.' : 'Campaign queued.',
    );
  }

  public function show(BulkEmailJob $bulkEmailJob): JsonResponse
  {
    $this->authorize('manage', BulkEmailJob::class);
    $bulkEmailJob->load('creator:id,uuid,name,email');

    return $this->responder->success(
      data: ['job' => $this->transform($bulkEmailJob, true)],
      message: 'Campaign retrieved.',
    );
  }

  public function recipients(Request $request, BulkEmailJob $bulkEmailJob, RecipientAudienceService $audience): JsonResponse
  {
    $this->authorize('manage', BulkEmailJob::class);
    $paginator = $this->recipientQuery($request, $bulkEmailJob)
      ->paginate(min(200, max(1, (int) $request->query('per_page', 50))));

    return $this->responder->success(
      data: PaginatedResponseBuilder::fromPaginator($paginator, fn (BulkEmailRecipient $r) => $this->transformRecipient($r, $audience)),
      message: 'Campaign recipients retrieved.',
    );
  }

  public function export(Request $request, BulkEmailJob $bulkEmailJob, RecipientAudienceService $audience): StreamedResponse
  {
    $this->authorize('manage', BulkEmailJob::class);
    $query = $this->recipientQuery($request, $bulkEmailJob);

    return response()->streamDownload(function () use ($query, $audience): void {
      $out = fopen('php://output', 'w');
      fputcsv($out, ['channel', 'name', 'destination', 'source', 'status', 'skip_reason', 'error_code', 'error_message', 'provider', 'provider_message_id', 'attempts', 'segments', 'cost', 'currency', 'accepted_at', 'delivered_at', 'read_at', 'failed_at']);
      $query->chunkById(500, function ($rows) use ($out, $audience): void {
        foreach ($rows as $row) {
          $r = $this->transformRecipient($row, $audience);
          fputcsv($out, array_map(fn ($v) => $this->csvSafe($v), [
            $r['channel'], $r['name'], $r['destination'], $r['source'], $r['status'], $r['skip_reason'], $r['error_code'], $r['error_message'],
            $r['provider'], $r['provider_message_id'], $r['attempts'], $r['segments'], $r['cost'], $r['currency'],
            $r['accepted_at'], $r['delivered_at'], $r['read_at'], $r['failed_at'],
          ]));
        }
      });
      fclose($out);
    }, 'campaign-'.$bulkEmailJob->uuid.'-recipients.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
  }

  public function cancel(Request $request, BulkEmailJob $bulkEmailJob, BulkEmailService $service): JsonResponse
  {
    $this->authorize('manage', BulkEmailJob::class);
    $service->cancel($bulkEmailJob, $request->user());

    return $this->responder->success(
      data: ['job' => $this->transform($bulkEmailJob->fresh() ?? $bulkEmailJob)],
      message: 'Campaign cancelled. Messages already accepted by a provider cannot be recalled.',
    );
  }

  public function resume(BulkEmailJob $bulkEmailJob, CampaignDispatchService $campaigns, BulkEmailService $service): JsonResponse
  {
    $this->authorize('manage', BulkEmailJob::class);
    if (! in_array($bulkEmailJob->status, [BulkEmailJob::STATUS_PROCESSING, 'sending', BulkEmailJob::STATUS_FAILED, BulkEmailJob::STATUS_PARTIAL], true)) {
      return $this->responder->error('Only processing, failed or partially completed campaigns can be resumed.', 'INVALID_STATE', 422);
    }
    $service->assertSendable($bulkEmailJob);
    $result = $campaigns->resume($bulkEmailJob);

    return $this->responder->success(
      data: ['job' => $this->transform($bulkEmailJob->fresh() ?? $bulkEmailJob), ...$result],
      message: 'Campaign resumed. Interrupted messages were not re-sent to avoid duplicates.',
    );
  }

  /**
   * Personalized preview for one eligible recipient (nothing is sent).
   */
  public function renderPreview(Request $request, RecipientAudienceService $audience, CampaignMessageRenderer $renderer, SmsSegmentCalculator $segments, CampaignManualRecipientService $manual): JsonResponse
  {
    $this->authorize('manage', BulkEmailJob::class);
    $validated = $request->validate([...self::FILTER_RULES, ...self::CONTENT_RULES, 'campaign_id' => ['nullable', 'uuid']]);
    $this->authorizeModule($request, $audience, $validated['recipient_filters']);

    $job = app(BulkEmailService::class)->makeUnsaved($validated);
    $channels = $job->channelList();

    $resolved = $audience->resolve($validated['recipient_filters'], $channels, (string) $job->message_type, 1, $manual->emailsFor($this->draftFor($validated['campaign_id'] ?? null)));
    $previews = [];
    foreach ($channels as $channel) {
      $first = $resolved['deliveries'][$channel][0] ?? null;
      $recipient = $first ?? ['name' => 'Sample Recipient', 'email' => 'sample@example.org', 'destination' => null];
      $vars = $renderer->variables($recipient, $job, $channel);
      if ($channel === 'email') {
        $previews['email'] = ['recipient' => $first ? $audience->mask('email', $first['destination']) : null, ...$renderer->email($job, $vars)];
      } elseif ($channel === 'sms') {
        $text = $renderer->text($job, $vars);
        $previews['sms'] = ['recipient' => $first ? $audience->mask('sms', $first['destination']) : null, 'text' => $text, 'segments' => $segments->calculate($text)];
      } else {
        $previews['whatsapp'] = ['recipient' => $first ? $audience->mask('whatsapp', $first['destination']) : null, 'template' => $renderer->whatsappTemplate($job, $vars)];
      }
    }

    return $this->responder->success(
      data: ['previews' => $previews, 'variables' => CampaignMessageRenderer::VARIABLES],
      message: 'Personalized preview rendered.',
    );
  }

  /**
   * Send the composed message to one test destination. Never sends to the audience.
   */
  public function testSend(Request $request, CampaignMessageRenderer $renderer, OutboundMessageService $outbound): JsonResponse
  {
    $this->authorize('manage', BulkEmailJob::class);
    $validated = $request->validate([
      ...self::CONTENT_RULES,
      'channel' => ['required', 'in:email,sms,whatsapp'],
      'to' => ['required', 'string', 'max:191'],
      'message_type' => ['nullable', 'in:promotional,transactional'],
    ]);

    $job = app(BulkEmailService::class)->makeUnsaved([...$validated, 'channels' => [$validated['channel']], 'recipient_filters' => ['module' => 'general']]);
    $vars = $renderer->variables(['name' => $request->user()?->name ?? 'Test Recipient', 'email' => $validated['to'], 'destination' => $validated['to']], $job, $validated['channel']);

    if ($validated['channel'] === 'email') {
      if (! filter_var($validated['to'], FILTER_VALIDATE_EMAIL)) {
        return $this->responder->error('Enter a valid test email address.', 'VALIDATION_FAILED', 422);
      }
      if (config('mail.default') === 'log') {
        return $this->responder->error('MAIL_MAILER is log; the test email was written to the log and not delivered.', 'MAILER_NOT_DELIVERING', 422);
      }
      $content = $renderer->email($job, $vars);
      try {
        Mail::to($validated['to'])->send(new CommunicationMailable($content['subject'], $content['html'], $content['text'], null, null, $job->from_name));
      } catch (\Throwable $exception) {
        return $this->responder->error('Test email failed: '.mb_substr($exception->getMessage(), 0, 200), 'MAIL_DELIVERY_FAILED', 422);
      }

      return $this->responder->success(data: ['status' => DeliveryStatus::ACCEPTED, 'channel' => 'email'], message: 'Test email accepted by the mail server. Check the inbox to confirm delivery.');
    }

    $template = $validated['channel'] === 'whatsapp' ? $renderer->whatsappTemplate($job, $vars) : null;
    if ($validated['channel'] === 'whatsapp' && $template === null) {
      return $this->responder->error('Select an approved WhatsApp template before sending a test.', 'TEMPLATE_REQUIRED', 422);
    }
    $row = $outbound->send($validated['channel'], $validated['to'], $renderer->text($job, $vars), [
      'subject' => '[TEST] '.$job->subject,
      'message_type' => 'transactional',
      'whatsapp_template' => $template,
      'module' => 'communications.test',
      'skip_consent_check' => $validated['channel'] !== 'whatsapp',
    ], $request->user());

    if ($row->status !== DeliveryStatus::ACCEPTED) {
      return $this->responder->error(
        'Test '.strtoupper($validated['channel']).' was not sent: '.($row->error_message ?: 'provider rejected the message.'),
        'PROVIDER_REJECTED',
        422,
        [],
        ['message' => ['id' => $row->uuid, 'status' => $row->status, 'error_code' => $row->error_code]],
      );
    }

    return $this->responder->success(
      data: ['status' => $row->status, 'channel' => $validated['channel'], 'id' => $row->uuid],
      message: 'Test '.strtoupper($validated['channel']).' accepted by the provider. Delivery will update when the provider reports it.',
    );
  }

  public function manualRecipients(Request $request, BulkEmailJob $bulkEmailJob, CampaignManualRecipientService $manual): JsonResponse
  {
    $this->authorize('manage', BulkEmailJob::class);
    $paginator = $manual->paginate($bulkEmailJob, $request->query('search'), (int) $request->query('per_page', 100));

    return $this->responder->success(
      data: [
        ...PaginatedResponseBuilder::fromPaginator($paginator, fn ($row) => ['email' => $row->email, 'added_at' => $row->created_at?->toIso8601String()]),
        'total_manual' => $manual->count($bulkEmailJob),
        'editable' => $bulkEmailJob->status === BulkEmailJob::STATUS_DRAFT,
        'chunk_limit' => CampaignManualRecipientService::chunkLimit(),
      ],
      message: 'Manual recipients retrieved.',
    );
  }

  public function storeManualRecipients(Request $request, BulkEmailJob $bulkEmailJob, CampaignManualRecipientService $manual): JsonResponse
  {
    $this->authorize('manage', BulkEmailJob::class);
    $validated = $request->validate([
      'emails' => ['required', 'array', 'min:1', 'max:'.CampaignManualRecipientService::chunkLimit()],
      'emails.*' => ['string', 'max:320'],
      'replace' => ['sometimes', 'boolean'],
    ]);

    $result = $manual->add($bulkEmailJob, array_values($validated['emails']), (bool) ($validated['replace'] ?? false));

    return $this->responder->success(data: $result, message: $result['added'].' address(es) added.');
  }

  public function destroyManualRecipients(Request $request, BulkEmailJob $bulkEmailJob, CampaignManualRecipientService $manual): JsonResponse
  {
    $this->authorize('manage', BulkEmailJob::class);
    $validated = $request->validate([
      'all' => ['sometimes', 'boolean'],
      'emails' => ['required_without:all', 'array', 'max:'.CampaignManualRecipientService::chunkLimit()],
      'emails.*' => ['string', 'max:320'],
    ]);

    $removed = ($validated['all'] ?? false)
      ? $manual->clear($bulkEmailJob)
      : $manual->remove($bulkEmailJob, array_values($validated['emails'] ?? []));

    return $this->responder->success(
      data: ['removed' => $removed, 'total' => $manual->count($bulkEmailJob)],
      message: $removed.' address(es) removed.',
    );
  }

  private function draftFor(?string $uuid): ?BulkEmailJob
  {
    if ($uuid === null || $uuid === '') {
      return null;
    }

    return BulkEmailJob::query()->where('uuid', $uuid)->firstOrFail();
  }

  /**
   * @param  array<string, mixed>  $filters
   */
  private function authorizeModule(Request $request, RecipientAudienceService $audience, array $filters): void
  {
    $module = $audience->moduleOf($filters);
    if (! $audience->canUseModule($request->user(), $module)) {
      abort(403, 'You do not have permission to message the '.$module.' audience.');
    }
  }

  /**
   * @param  array<string, mixed>  $preview
   * @return array<string, mixed>
   */
  private function costEstimate(array $preview, ?string $text): array
  {
    $currency = (string) config('communications.costs.currency', 'NGN');
    $smsRate = config('communications.costs.sms_per_segment');
    $waRate = config('communications.costs.whatsapp_per_message');
    $segments = $text !== null && $text !== '' ? app(SmsSegmentCalculator::class)->calculate($text) : null;
    $smsCount = (int) ($preview['channel_stats']['sms']['eligible'] ?? 0);
    $waCount = (int) ($preview['channel_stats']['whatsapp']['eligible'] ?? 0);

    return [
      'currency' => $currency,
      'sms' => [
        'messages' => $smsCount,
        'segments_per_message' => $segments['segments'] ?? null,
        'encoding' => $segments['encoding'] ?? null,
        'characters' => $segments['characters'] ?? null,
        'total_segments' => $segments ? $segments['segments'] * $smsCount : null,
        'estimated_cost' => $segments && is_numeric($smsRate) ? round($segments['segments'] * $smsCount * (float) $smsRate, 2) : null,
        'note' => is_numeric($smsRate) ? 'Estimate before personalization; names change length per recipient.' : 'Set SMS_COST_PER_SEGMENT to show a cost estimate.',
      ],
      'whatsapp' => [
        'messages' => $waCount,
        'estimated_cost' => is_numeric($waRate) ? round($waCount * (float) $waRate, 2) : null,
        'note' => is_numeric($waRate) ? 'Meta bills per conversation by template category and country; this is an estimate.' : 'Set WHATSAPP_COST_PER_MESSAGE to show a cost estimate.',
      ],
      'email' => ['messages' => (int) ($preview['channel_stats']['email']['eligible'] ?? 0), 'estimated_cost' => null],
    ];
  }

  private function recipientQuery(Request $request, BulkEmailJob $job)
  {
    return BulkEmailRecipient::query()
      ->where('bulk_email_job_id', $job->id)
      ->when($request->query('channel'), fn ($q, $c) => $q->where('channel', $c))
      ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
      ->when($request->query('source'), fn ($q, $s) => $q->where('source', $s))
      ->orderBy('id');
  }

  /**
   * @return array<string, mixed>
   */
  private function transformRecipient(BulkEmailRecipient $r, RecipientAudienceService $audience): array
  {
    $channel = (string) ($r->channel ?: 'email');

    return [
      'id' => $r->id,
      'channel' => $channel,
      'name' => $r->name,
      'destination' => $audience->mask($channel, $r->destination ?: $r->email),
      'source' => $r->source ?? 'audience',
      'status' => $r->status === DeliveryStatus::LEGACY_SENT ? DeliveryStatus::ACCEPTED : $r->status,
      'skip_reason' => $r->skip_reason,
      'error_code' => $r->error_code,
      'error_message' => $r->error_message,
      'provider' => $r->provider,
      'provider_message_id' => $r->provider_message_id,
      'attempts' => $r->attempts,
      'segments' => $r->segments,
      'cost' => $r->cost,
      'currency' => $r->currency,
      'accepted_at' => ($r->accepted_at ?? $r->sent_at)?->toIso8601String(),
      'delivered_at' => $r->delivered_at?->toIso8601String(),
      'read_at' => $r->read_at?->toIso8601String(),
      'failed_at' => $r->failed_at?->toIso8601String(),
    ];
  }

  private function csvSafe(mixed $value): string
  {
    $value = (string) ($value ?? '');

    return preg_match('/^[=+\-@\t\r]/', $value) ? "'".$value : $value;
  }

  /**
   * @return array<string, mixed>
   */
  private function transform(BulkEmailJob $j, bool $detail = false): array
  {
    $data = [
      'id' => $j->uuid,
      'subject' => $j->subject,
      'status' => $j->status === 'sending' ? BulkEmailJob::STATUS_PROCESSING : $j->status,
      'channels' => $j->channelList(),
      'module' => $j->module ?? (($j->recipient_filters ?? [])['module'] ?? null),
      'message_type' => $j->message_type ?? 'promotional',
      'from_name' => $j->from_name,
      'from_email' => $j->from_email,
      'recipient_filters' => $j->recipient_filters,
      'estimated_count' => $j->estimated_count,
      'sent_count' => $j->sent_count,
      'accepted_count' => $j->accepted_count ?? 0,
      'delivered_count' => $j->delivered_count ?? 0,
      'failed_count' => $j->failed_count,
      'skipped_count' => $j->skipped_count ?? 0,
      'last_error' => $j->last_error,
      'whatsapp_template' => $j->whatsapp_template ? ['template_id' => $j->whatsapp_template['template_id'] ?? null, 'name' => $j->whatsapp_template['name'] ?? null, 'language' => $j->whatsapp_template['language'] ?? null] : null,
      'created_by' => $j->relationLoaded('creator') ? $j->creator?->name : null,
      'queued_at' => $j->queued_at?->toIso8601String(),
      'scheduled_at' => $j->scheduled_at?->toIso8601String(),
      'started_at' => $j->started_at?->toIso8601String(),
      'completed_at' => $j->completed_at?->toIso8601String(),
      'cancelled_at' => $j->cancelled_at?->toIso8601String(),
      'created_at' => $j->created_at?->toIso8601String(),
    ];

    if ($detail) {
      $data['subject'] = $j->subject;
      $data['html_body'] = $j->html_body;
      $data['text_body'] = $j->text_body;
      $data['preview_snapshot'] = $j->preview_snapshot;
      $data['channel_breakdown'] = BulkEmailRecipient::query()
        ->where('bulk_email_job_id', $j->id)
        ->selectRaw('channel, status, COUNT(*) as aggregate')
        ->groupBy('channel', 'status')
        ->get()
        ->groupBy('channel')
        ->map(fn ($rows) => $rows->mapWithKeys(fn ($row) => [($row->status === DeliveryStatus::LEGACY_SENT ? DeliveryStatus::ACCEPTED : $row->status) => (int) $row->aggregate]))
        ->all();
      $data['skip_reasons'] = BulkEmailRecipient::query()
        ->where('bulk_email_job_id', $j->id)
        ->where('status', DeliveryStatus::SKIPPED)
        ->selectRaw('channel, skip_reason, COUNT(*) as aggregate')
        ->groupBy('channel', 'skip_reason')
        ->get()
        ->map(fn ($row) => ['channel' => $row->channel, 'reason' => $row->skip_reason, 'count' => (int) $row->aggregate])
        ->all();
      $data['cost_total'] = (float) BulkEmailRecipient::query()->where('bulk_email_job_id', $j->id)->sum('cost');
      $data['manual_recipient_count'] = app(CampaignManualRecipientService::class)->count($j);
      $data['source_breakdown'] = BulkEmailRecipient::query()
        ->where('bulk_email_job_id', $j->id)
        ->selectRaw('source, status, COUNT(*) as aggregate')
        ->groupBy('source', 'status')
        ->get()
        ->groupBy(fn ($row) => $row->source ?? 'audience')
        ->map(fn ($rows) => $rows->mapWithKeys(fn ($row) => [($row->status === DeliveryStatus::LEGACY_SENT ? DeliveryStatus::ACCEPTED : $row->status) => (int) $row->aggregate]))
        ->all();
    }

    return $data;
  }
}
