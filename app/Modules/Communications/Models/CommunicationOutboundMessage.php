<?php

declare(strict_types=1);

namespace App\Modules\Communications\Models;

use App\Modules\Communications\Support\HasCommunicationUuid;
use Illuminate\Database\Eloquent\Model;

class CommunicationOutboundMessage extends Model
{
    use HasCommunicationUuid;

    protected $fillable = [
        'uuid',
        'channel',
        'to',
        'status',
        'provider',
        'provider_message_id',
        'body',
        'subject',
        'error_message',
        'error_code',
        'metadata',
        'created_by_user_id',
        'queued_at',
        'sent_at',
        'delivered_at',
        'read_at',
        'failed_at',
        'last_event_at',
        'segments',
        'cost',
        'currency',
        'idempotency_key',
        'bulk_email_recipient_id',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'queued_at' => 'datetime',
            'sent_at' => 'datetime',
            'delivered_at' => 'datetime',
            'read_at' => 'datetime',
            'failed_at' => 'datetime',
            'last_event_at' => 'datetime',
            'segments' => 'integer',
            'cost' => 'float',
        ];
    }
}
