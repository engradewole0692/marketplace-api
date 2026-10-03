<?php

declare(strict_types=1);

use App\Modules\Communications\Services\CommunicationSeederDefaults;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public const PREVIOUS_DEFAULT_BODY = '<p>Hello {{applicant_name}},</p><p>You are registered for <strong>{{event_name}}</strong> on {{event_date}} at {{event_location}}.</p><p>Your registration reference is <strong>{{registration_number}}</strong>.</p><p>{{check_in_instructions}}</p><p><img src="{{qr_image_url}}" alt="Event check-in QR" width="240" height="240" /></p><p>If the image does not display, staff can enter this check-in code: <strong>{{qr_token}}</strong></p>';

    /**
     * Only rows still holding the previous system default are upgraded; admin-customized bodies are left untouched.
     */
    public function up(): void
    {
        if (! Schema::hasTable('communication_templates')) {
            return;
        }

        $defaults = app(CommunicationSeederDefaults::class)->templateDefaults('event.registration.confirmed');
        if (! is_array($defaults) || $defaults['html_body'] === self::PREVIOUS_DEFAULT_BODY) {
            return;
        }

        DB::table('communication_templates')
            ->where('event_key', 'event.registration.confirmed')
            ->where('html_body', self::PREVIOUS_DEFAULT_BODY)
            ->update([
                'html_body' => $defaults['html_body'],
                'text_body' => $defaults['text_body'],
                'available_variables' => json_encode($defaults['available_variables']),
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('communication_templates')) {
            return;
        }

        $defaults = app(CommunicationSeederDefaults::class)->templateDefaults('event.registration.confirmed');
        if (! is_array($defaults)) {
            return;
        }

        DB::table('communication_templates')
            ->where('event_key', 'event.registration.confirmed')
            ->where('html_body', $defaults['html_body'])
            ->update([
                'html_body' => self::PREVIOUS_DEFAULT_BODY,
                'text_body' => strip_tags(str_replace(['</p>', '<br>', '<br/>'], ["\n\n", "\n", "\n"], self::PREVIOUS_DEFAULT_BODY)),
                'available_variables' => json_encode(['applicant_name', 'event_name', 'event_date', 'event_time', 'event_location', 'event_url', 'registration_number', 'qr_token', 'qr_image_url', 'check_in_instructions']),
                'updated_at' => now(),
            ]);
    }
};
