<?php

declare(strict_types=1);

use App\Modules\Events\Services\RegistrationFormConfigService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public const PREVIOUS_DEFAULT_LABEL = 'Organization / company';

    /**
     * Only rows still holding the previous system default are relabelled; admin-customized labels are left untouched.
     */
    public function up(): void
    {
        if (! Schema::hasTable('event_registration_field_settings')) {
            return;
        }

        DB::table('event_registration_field_settings')
            ->where('field_key', 'organization')
            ->whereRaw('LOWER(TRIM(label)) = ?', [strtolower(self::PREVIOUS_DEFAULT_LABEL)])
            ->update([
                'label' => RegistrationFormConfigService::ORGANIZATION_LABEL,
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('event_registration_field_settings')) {
            return;
        }

        DB::table('event_registration_field_settings')
            ->where('field_key', 'organization')
            ->where('label', RegistrationFormConfigService::ORGANIZATION_LABEL)
            ->update([
                'label' => self::PREVIOUS_DEFAULT_LABEL,
                'updated_at' => now(),
            ]);
    }
};
