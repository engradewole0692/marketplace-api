<?php

declare(strict_types=1);

namespace App\Modules\Events\Models;

use App\Models\Member;
use App\Models\Person;
use App\Models\User;
use App\Modules\Events\Enums\CheckInMethod;
use App\Modules\Events\Enums\DayAttendanceStatus;
use App\Modules\Events\Enums\SeatingArea;
use App\Modules\Events\Support\HasEventUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventSessionAttendance extends Model
{
    use HasEventUuid;

    protected $fillable = [
        'uuid',
        'event_id',
        'event_session_id',
        'event_day_id',
        'registration_id',
        'person_id',
        'member_id',
        'status',
        'method',
        'seating_area',
        'counts_toward_seating',
        'capacity_overridden',
        'override_reason',
        'checked_in_by_user_id',
        'checked_in_at',
        'checked_out_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'status' => DayAttendanceStatus::class,
            'method' => CheckInMethod::class,
            'seating_area' => SeatingArea::class,
            'counts_toward_seating' => 'boolean',
            'capacity_overridden' => 'boolean',
            'checked_in_at' => 'datetime',
            'checked_out_at' => 'datetime',
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

    public function session(): BelongsTo
    {
        return $this->belongsTo(EventSession::class, 'event_session_id');
    }

    public function day(): BelongsTo
    {
        return $this->belongsTo(EventDay::class, 'event_day_id');
    }

    public function registration(): BelongsTo
    {
        return $this->belongsTo(EventRegistration::class, 'registration_id');
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function checkedInBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'checked_in_by_user_id');
    }

    public function isOpen(): bool
    {
        $status = $this->status instanceof DayAttendanceStatus
            ? $this->status
            : DayAttendanceStatus::tryFrom((string) $this->status);

        return $status === DayAttendanceStatus::CheckedIn && $this->checked_out_at === null;
    }
}
