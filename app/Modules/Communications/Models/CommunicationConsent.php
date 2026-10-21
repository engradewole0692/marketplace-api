<?php

declare(strict_types=1);

namespace App\Modules\Communications\Models;

use App\Modules\Communications\Support\HasCommunicationUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Per-channel consent and suppression for one normalized destination.
 * status: opted_in | opted_out | suppressed. purpose: promotional | all.
 */
class CommunicationConsent extends Model
{
    use HasCommunicationUuid;

    public const OPTED_IN = 'opted_in';

    public const OPTED_OUT = 'opted_out';

    public const SUPPRESSED = 'suppressed';

    protected $fillable = [
        'uuid', 'channel', 'destination', 'purpose', 'status', 'source', 'reason',
        'person_id', 'user_id', 'recorded_by_user_id',
        'opted_in_at', 'opted_out_at', 'suppressed_at', 'metadata',
    ];

    protected function casts(): array
    {
        return [
            'opted_in_at' => 'datetime',
            'opted_out_at' => 'datetime',
            'suppressed_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function events(): HasMany
    {
        return $this->hasMany(CommunicationConsentEvent::class, 'consent_id');
    }
}
