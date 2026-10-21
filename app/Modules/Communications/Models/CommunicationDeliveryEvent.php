<?php

declare(strict_types=1);

namespace App\Modules\Communications\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Provider callback history. event_key is unique per provider so replays are ignored.
 */
class CommunicationDeliveryEvent extends Model
{
    protected $fillable = [
        'provider', 'channel', 'provider_message_id', 'event_key', 'status', 'raw_status',
        'error_code', 'error_message', 'occurred_at', 'applied', 'payload',
    ];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'applied' => 'boolean',
            'payload' => 'array',
        ];
    }
}
