<?php

declare(strict_types=1);

namespace App\Models;

use App\Modules\Cms\Models\CmsCountry;
use App\Modules\Events\Models\EventRegistration;
use App\Modules\Events\Support\HasEventUuid;
use Database\Factories\PersonFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Person extends Model
{
    /** @use HasFactory<PersonFactory> */
    use HasFactory;
    use HasEventUuid;
    use SoftDeletes;

    protected $table = 'persons';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'uuid',
        'person_no',
        'user_id',
        'first_name',
        'last_name',
        'display_name',
        'email',
        'phone',
        'phone_digits',
        'country_id',
        'region',
        'city',
        'organization',
        'meta',
    ];

    protected static function booted(): void
    {
        static::saving(function (Person $person): void {
            if ($person->email !== null) {
                $email = strtolower(trim((string) $person->email));
                $person->email = $email === '' ? null : $email;
            }

            $person->phone_digits = self::digits($person->phone);

            if (empty($person->display_name)) {
                $person->display_name = trim(trim((string) $person->first_name).' '.trim((string) $person->last_name))
                    ?: ($person->email ?: 'Participant');
            }
        });

        static::created(function (Person $person): void {
            if (empty($person->person_no) || str_starts_with((string) $person->person_no, 'PERSON-TMP-')) {
                $person->person_no = 'PERSON-'.str_pad((string) $person->id, 6, '0', STR_PAD_LEFT);
                $person->saveQuietly();
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'meta' => 'array',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function resolveRouteBinding($value, $field = null): ?Model
    {
        $field ??= $this->getRouteKeyName();
        $query = $this->newQuery();

        if (is_numeric($value) && ! str_contains((string) $value, '-')) {
            return $query->where($this->getKeyName(), $value)->first();
        }

        return $query->where($field, $value)->first();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function member(): HasOne
    {
        return $this->hasOne(Member::class);
    }

    public function country(): BelongsTo
    {
        return $this->belongsTo(CmsCountry::class, 'country_id');
    }

    public function eventRegistrations(): HasMany
    {
        return $this->hasMany(EventRegistration::class);
    }

    public function fullName(): string
    {
        return $this->display_name
            ?: trim(trim((string) $this->first_name).' '.trim((string) $this->last_name))
            ?: ($this->email ?: $this->person_no);
    }

    public static function digits(?string $phone): ?string
    {
        if ($phone === null || trim($phone) === '') {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        return $digits === '' ? null : $digits;
    }

    public static function nextTemporaryNumber(): string
    {
        return 'PERSON-TMP-'.Str::lower(Str::random(10));
    }
}
