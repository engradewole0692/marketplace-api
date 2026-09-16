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
        'metadata',
        'created_by_user_id',
        'queued_at',
        'sent_at',
        'delivered_at',
        'failed_at',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'queued_at' => 'datetime',
            'sent_at' => 'datetime',
            'delivered_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }
}
