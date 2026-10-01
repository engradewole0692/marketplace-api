<?php

declare(strict_types=1);

namespace App\Modules\Events\Models;

use App\Models\User;
use App\Modules\Events\Support\HasEventUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventRegistrationResetSnapshot extends Model
{
    use HasEventUuid;

    protected $fillable = [
        'uuid',
        'event_id',
        'actor_id',
        'reset_type',
        'registration_count',
        'affected_counts',
        'payload',
        'restored_at',
        'restored_by_user_id',
        'expires_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'registration_count' => 'integer',
            'affected_counts' => 'array',
            'payload' => 'array',
            'restored_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function restoredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'restored_by_user_id');
    }

    public function isRestored(): bool
    {
        return $this->restored_at !== null;
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && now()->gte($this->expires_at);
    }

    public function canRestore(): bool
    {
        return ! $this->isRestored() && ! $this->isExpired();
    }
}
