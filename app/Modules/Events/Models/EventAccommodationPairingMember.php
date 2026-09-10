<?php

declare(strict_types=1);

namespace App\Modules\Events\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventAccommodationPairingMember extends Model
{
    protected $fillable = [
        'pairing_id',
        'registration_id',
        'status',
        'confirmed_at',
        'declined_at',
    ];

    protected function casts(): array
    {
        return [
            'confirmed_at' => 'datetime',
            'declined_at' => 'datetime',
        ];
    }

    public function pairing(): BelongsTo
    {
        return $this->belongsTo(EventAccommodationPairing::class, 'pairing_id');
    }

    public function registration(): BelongsTo
    {
        return $this->belongsTo(EventRegistration::class, 'registration_id');
    }
}
