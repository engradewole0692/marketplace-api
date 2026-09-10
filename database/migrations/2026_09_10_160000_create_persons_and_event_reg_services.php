<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Canonical person identity for event participants (members and non-members).
 * One person, many event registrations. Event-specific answers stay on registrations.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('persons')) {
            Schema::create('persons', function (Blueprint $table): void {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->string('person_no', 32)->unique();
                $table->foreignId('user_id')->nullable()->unique()->constrained('users')->nullOnDelete();
                $table->string('first_name', 120)->nullable();
                $table->string('last_name', 120)->nullable();
                $table->string('display_name', 255);
                $table->string('email', 255)->nullable();
                $table->string('phone', 40)->nullable();
                $table->string('phone_digits', 40)->nullable();
                $table->unsignedBigInteger('country_id')->nullable()->index();
                $table->string('region', 120)->nullable();
                $table->string('city', 120)->nullable();
                $table->string('organization', 255)->nullable();
                $table->json('meta')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index('email');
                $table->index('phone_digits');
                $table->index(['last_name', 'first_name']);
            });
        }

        if (Schema::hasTable('members') && ! Schema::hasColumn('members', 'person_id')) {
            Schema::table('members', function (Blueprint $table): void {
                $table->foreignId('person_id')->nullable()->after('user_id')->constrained('persons')->nullOnDelete();
            });
        }

        if (Schema::hasTable('event_registrations') && ! Schema::hasColumn('event_registrations', 'person_id')) {
            Schema::table('event_registrations', function (Blueprint $table): void {
                $table->foreignId('person_id')->nullable()->after('member_id')->constrained('persons')->nullOnDelete();
            });
        }

        if (! Schema::hasTable('event_reg_services')) {
            Schema::create('event_reg_services', function (Blueprint $table): void {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->foreignId('registration_id')->constrained('event_registrations')->cascadeOnDelete();
                $table->string('type', 32);
                $table->string('status', 40);
                $table->json('details')->nullable();
                $table->timestamp('confirmed_at')->nullable();
                $table->timestamps();

                $table->unique(['registration_id', 'type']);
                $table->index(['type', 'status']);
            });
        }

        $this->backfillPersonsFromMembers();
        $this->backfillPersonsFromRegistrations();
        $this->backfillRequestedServices();
        $this->addEventPersonUniqueIndex();
    }

    public function down(): void
    {
        Schema::dropIfExists('event_reg_services');

        if (Schema::hasTable('event_registrations') && Schema::hasColumn('event_registrations', 'person_id')) {
            Schema::table('event_registrations', function (Blueprint $table): void {
                $table->dropConstrainedForeignId('person_id');
            });
        }

        if (Schema::hasTable('members') && Schema::hasColumn('members', 'person_id')) {
            Schema::table('members', function (Blueprint $table): void {
                $table->dropConstrainedForeignId('person_id');
            });
        }

        Schema::dropIfExists('persons');
    }

    private function backfillPersonsFromMembers(): void
    {
        if (! Schema::hasTable('members') || ! Schema::hasColumn('members', 'person_id')) {
            return;
        }

        DB::table('members')->orderBy('id')->chunkById(100, function ($members): void {
            foreach ($members as $member) {
                if ($member->person_id) {
                    continue;
                }

                $email = $this->normalizeEmail($member->email ?? null);
                $personId = $this->findPersonId($email, $member->phone ?? null, $member->user_id ?? null);

                if ($personId === null) {
                    $personId = $this->insertPerson([
                        'user_id' => $member->user_id ?: null,
                        'first_name' => $member->first_name,
                        'last_name' => $member->last_name,
                        'display_name' => $member->display_name
                            ?: trim(trim((string) $member->first_name).' '.trim((string) $member->last_name))
                            ?: (string) ($member->email ?? 'Participant'),
                        'email' => $email,
                        'phone' => $member->phone,
                        'country_id' => $member->country_id,
                        'region' => $member->state,
                        'city' => $member->city,
                        'organization' => $member->organization,
                    ]);
                } elseif ($member->user_id) {
                    DB::table('persons')->where('id', $personId)->whereNull('user_id')->update([
                        'user_id' => $member->user_id,
                        'updated_at' => now(),
                    ]);
                }

                DB::table('members')->where('id', $member->id)->update(['person_id' => $personId]);
            }
        });
    }

    private function backfillPersonsFromRegistrations(): void
    {
        if (! Schema::hasTable('event_registrations') || ! Schema::hasColumn('event_registrations', 'person_id')) {
            return;
        }

        DB::table('event_registrations')->orderBy('id')->chunkById(100, function ($rows): void {
            foreach ($rows as $row) {
                if ($row->person_id) {
                    continue;
                }

                $personId = null;
                if ($row->member_id) {
                    $personId = DB::table('members')->where('id', $row->member_id)->value('person_id');
                }

                $email = $this->normalizeEmail($row->guest_email ?? null);
                $phone = $row->guest_phone ?? null;

                if ($personId === null) {
                    $personId = $this->findPersonId($email, $phone, null);
                }

                if ($personId === null) {
                    $name = trim((string) ($row->guest_name ?? ''));
                    [$first, $last] = $this->splitName($name);
                    $personId = $this->insertPerson([
                        'first_name' => $first,
                        'last_name' => $last,
                        'display_name' => $name !== '' ? $name : ($email ?: 'Participant'),
                        'email' => $email,
                        'phone' => $phone,
                    ]);
                }

                if ($this->registrationPersonTaken((int) $row->event_id, (int) $personId)) {
                    continue;
                }

                DB::table('event_registrations')->where('id', $row->id)->update(['person_id' => $personId]);
            }
        });
    }

    private function backfillRequestedServices(): void
    {
        if (! Schema::hasTable('event_reg_services') || ! Schema::hasTable('event_registrations')) {
            return;
        }

        DB::table('event_registrations')->orderBy('id')->chunkById(100, function ($rows): void {
            foreach ($rows as $row) {
                if ($row->accommodation_required) {
                    $this->ensureService((int) $row->id, 'accommodation', 'requested');
                }
                if ($row->airport_pickup_required) {
                    $this->ensureService((int) $row->id, 'transport', 'requested', ['service' => 'airport_pickup']);
                }
            }
        });
    }

    private function addEventPersonUniqueIndex(): void
    {
        if (! Schema::hasTable('event_registrations') || ! Schema::hasColumn('event_registrations', 'person_id')) {
            return;
        }

        $duplicates = DB::table('event_registrations')
            ->select('event_id', 'person_id', DB::raw('COUNT(*) as c'))
            ->whereNotNull('person_id')
            ->groupBy('event_id', 'person_id')
            ->having('c', '>', 1)
            ->exists();

        if ($duplicates) {
            Schema::table('event_registrations', function (Blueprint $table): void {
                $table->index(['event_id', 'person_id'], 'event_regs_event_person_idx');
            });

            return;
        }

        Schema::table('event_registrations', function (Blueprint $table): void {
            $table->unique(['event_id', 'person_id'], 'event_regs_event_person_unique');
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function insertPerson(array $attributes): int
    {
        $now = now();
        $id = DB::table('persons')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'person_no' => 'PERSON-TMP-'.Str::lower(Str::random(10)),
            'user_id' => $attributes['user_id'] ?? null,
            'first_name' => $attributes['first_name'] ?? null,
            'last_name' => $attributes['last_name'] ?? null,
            'display_name' => $attributes['display_name'] ?: 'Participant',
            'email' => $attributes['email'] ?? null,
            'phone' => $attributes['phone'] ?? null,
            'phone_digits' => $this->digits($attributes['phone'] ?? null),
            'country_id' => $attributes['country_id'] ?? null,
            'region' => $attributes['region'] ?? null,
            'city' => $attributes['city'] ?? null,
            'organization' => $attributes['organization'] ?? null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('persons')->where('id', $id)->update([
            'person_no' => 'PERSON-'.str_pad((string) $id, 6, '0', STR_PAD_LEFT),
        ]);

        return $id;
    }

    private function findPersonId(?string $email, ?string $phone, mixed $userId): ?int
    {
        if (is_numeric($userId) && (int) $userId > 0) {
            $id = DB::table('persons')->where('user_id', (int) $userId)->value('id');
            if ($id) {
                return (int) $id;
            }
        }

        if ($email) {
            $id = DB::table('persons')->whereRaw('LOWER(email) = ?', [$email])->value('id');
            if ($id) {
                return (int) $id;
            }
        }

        $digits = $this->digits($phone);
        if ($digits !== null) {
            $matches = DB::table('persons')->where('phone_digits', $digits)->pluck('id');
            if ($matches->count() === 1) {
                return (int) $matches->first();
            }
        }

        return null;
    }

    private function registrationPersonTaken(int $eventId, int $personId): bool
    {
        return DB::table('event_registrations')
            ->where('event_id', $eventId)
            ->where('person_id', $personId)
            ->exists();
    }

    /**
     * @param  array<string, mixed>|null  $details
     */
    private function ensureService(int $registrationId, string $type, string $status, ?array $details = null): void
    {
        $exists = DB::table('event_reg_services')
            ->where('registration_id', $registrationId)
            ->where('type', $type)
            ->exists();

        if ($exists) {
            return;
        }

        $now = now();
        DB::table('event_reg_services')->insert([
            'uuid' => (string) Str::uuid(),
            'registration_id' => $registrationId,
            'type' => $type,
            'status' => $status,
            'details' => $details === null ? null : json_encode($details),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function normalizeEmail(mixed $email): ?string
    {
        if (! is_string($email)) {
            return null;
        }
        $email = strtolower(trim($email));

        return $email === '' ? null : $email;
    }

    private function digits(mixed $phone): ?string
    {
        if (! is_string($phone) || trim($phone) === '') {
            return null;
        }
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        return $digits === '' ? null : $digits;
    }

    /**
     * @return array{0: ?string, 1: ?string}
     */
    private function splitName(string $name): array
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        if ($parts === []) {
            return [null, null];
        }
        $first = array_shift($parts);

        return [$first !== '' ? $first : null, $parts === [] ? null : implode(' ', $parts)];
    }
};
