<?php

declare(strict_types=1);

namespace App\Modules\Communications\Models;

use Illuminate\Database\Eloquent\Model;

class CommunicationConsentEvent extends Model
{
    protected $fillable = [
        'consent_id', 'action', 'from_status', 'to_status', 'source', 'reason', 'actor_user_id', 'metadata',
    ];

    protected function casts(): array
    {
        return ['metadata' => 'array'];
    }
}
