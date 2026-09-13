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
        'check_in_date',
        'check_out_date',
        'actual_nights',
        'confirmed_at',
        'declined_at',
    ];

    protected function casts(): array
    {
        return [
            'confirmed_at' => 'datetime',
            'declined_at' => 'datetime',
            'check_in_date' => 'date',
            'check_out_date' => 'date',
            'actual_nights' => 'integer',
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
