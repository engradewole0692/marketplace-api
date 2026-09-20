<?php

declare(strict_types=1);

namespace App\Modules\Communications\Services;

use App\Modules\Communications\Models\BulkEmailJob;
use App\Modules\Communications\Models\BulkEmailRecipient;
use App\Modules\Communications\Models\CommunicationEmailLog;
use App\Modules\Communications\Models\CommunicationOutboundMessage;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;
use Illuminate\Support\Collection;

final class CommunicationActivityService
{
    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function paginate(array $filters = [], int $perPage = 25): LengthAwarePaginator
    {
        $channel = strtolower((string) ($filters['channel'] ?? ''));
        $status = strtolower((string) ($filters['status'] ?? ''));
        $recipient = trim((string) ($filters['recipient'] ?? ''));
        $module = strtolower((string) ($filters['module'] ?? ''));

        $rows = collect()
            ->concat($this->emailRows($channel, $status, $recipient, $module))
            ->concat($this->outboundRows($channel, $status, $recipient, $module))
            ->concat($this->bulkRows($channel, $status, $recipient, $module))
            ->sortByDesc(fn (array $row) => $row['timestamp'] ?? '')
            ->values();

        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = min(100, max(1, $perPage));
        $slice = $rows->forPage($page, $perPage)->values();

        return new Paginator($slice, $rows->count(), $perPage, $page, [
            'path' => request()->url(),
            'query' => request()->query(),
        ]);
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function emailRows(string $channel, string $status, string $recipient, string $module): Collection
    {
        if ($channel !== '' && $channel !== 'email') {
            return collect();
        }

        $query = CommunicationEmailLog::query()->with('template')->latest();
        if ($status !== '') {
            $query->where('status', $status);
        }
        if ($recipient !== '') {
            $query->where('recipient_email', 'like', '%'.$recipient.'%');
        }
        if ($module !== '') {
            $query->where('section', $module);
        }

        return $query->limit(500)->get()->map(fn (CommunicationEmailLog $log): array => [
            'id' => $log->uuid,
            'channel' => 'email',
            'module' => $log->section,
            'campaign' => $log->event_key,
            'recipient' => $log->recipient_email,
            'status' => $log->status instanceof \BackedEnum ? $log->status->value : (string) $log->status,
            'provider_message_id' => null,
            'failure_reason' => $log->error_message,
            'sender' => $log->sender_email,
            'timestamp' => $log->sent_at?->toIso8601String() ?? $log->created_at?->toIso8601String(),
            'queued_at' => $log->created_at?->toIso8601String(),
            'sent_at' => $log->sent_at?->toIso8601String(),
            'delivered_at' => $log->status instanceof \BackedEnum && $log->status->value === 'sent'
                ? $log->sent_at?->toIso8601String()
                : null,
            'failed_at' => $log->failed_at?->toIso8601String(),
        ]);
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function outboundRows(string $channel, string $status, string $recipient, string $module): Collection
    {
        if ($channel !== '' && ! in_array($channel, ['sms', 'whatsapp'], true)) {
            return collect();
        }

        $query = CommunicationOutboundMessage::query()->latest();
        if ($channel !== '') {
            $query->where('channel', $channel);
        }
        if ($status !== '') {
            $query->where('status', $status);
        }
        if ($recipient !== '') {
            $query->where('to', 'like', '%'.$recipient.'%');
        }

        return $query->limit(500)->get()->map(fn (CommunicationOutboundMessage $row): array => [
            'id' => $row->uuid,
            'channel' => $row->channel,
            'module' => is_array($row->metadata) ? ($row->metadata['module'] ?? null) : null,
            'campaign' => $row->subject,
            'recipient' => $row->to,
            'status' => $row->status,
            'provider_message_id' => $row->provider_message_id,
            'failure_reason' => $row->error_message,
            'sender' => $row->created_by_user_id,
            'timestamp' => $row->sent_at?->toIso8601String() ?? $row->created_at?->toIso8601String(),
            'queued_at' => $row->queued_at?->toIso8601String(),
            'sent_at' => $row->sent_at?->toIso8601String(),
            'delivered_at' => $row->delivered_at?->toIso8601String(),
            'failed_at' => $row->failed_at?->toIso8601String(),
        ])->when($module !== '', fn (Collection $rows) => $rows->filter(
            fn (array $row) => strtolower((string) ($row['module'] ?? '')) === $module,
        )->values());
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function bulkRows(string $channel, string $status, string $recipient, string $module): Collection
    {
        $query = BulkEmailRecipient::query()->with('job')->latest();
        if ($status !== '') {
            $query->where('status', $status);
        }
        if ($recipient !== '') {
            $query->where(function ($builder) use ($recipient): void {
                $builder->where('email', 'like', '%'.$recipient.'%')
                    ->orWhere('name', 'like', '%'.$recipient.'%');
            });
        }

        return $query->limit(500)->get()->map(function (BulkEmailRecipient $row): array {
            $filters = is_array($row->job?->recipient_filters) ? $row->job->recipient_filters : [];

            return [
                'id' => 'bulk-'.$row->id,
                'channel' => $filters['channel'] ?? 'email',
                'module' => $filters['module'] ?? null,
                'campaign' => $row->job?->subject,
                'audience' => $filters,
                'recipient' => $row->email,
                'status' => $row->status,
                'provider_message_id' => null,
                'failure_reason' => $row->error_message,
                'sender' => $row->job?->created_by,
                'timestamp' => $row->sent_at?->toIso8601String() ?? $row->created_at?->toIso8601String(),
                'queued_at' => $row->job?->queued_at?->toIso8601String(),
                'sent_at' => $row->sent_at?->toIso8601String(),
                'delivered_at' => $row->status === 'sent' ? $row->sent_at?->toIso8601String() : null,
                'failed_at' => $row->status === 'failed' ? $row->updated_at?->toIso8601String() : null,
            ];
        })->when($channel !== '', fn (Collection $rows) => $rows->filter(
            fn (array $row) => strtolower((string) ($row['channel'] ?? '')) === $channel,
        )->values())->when($module !== '', fn (Collection $rows) => $rows->filter(
            fn (array $row) => strtolower((string) ($row['module'] ?? '')) === $module,
        )->values());
    }
}
