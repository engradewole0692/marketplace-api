<?php

declare(strict_types=1);

namespace App\Modules\Cms\Notifications;

use App\Modules\Cms\Contracts\WhatsAppNotifierContract;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

final class MetaWhatsAppNotifier implements WhatsAppNotifierContract
{
    public function send(string $to, string $message, array $context = []): bool
    {
        $token = (string) config('communications.meta_whatsapp.token');
        $phoneNumberId = (string) config('communications.meta_whatsapp.phone_number_id');
        if ($token === '' || $phoneNumberId === '') {
            Log::warning('cms.whatsapp.meta.unconfigured');

            return false;
        }

        $digits = preg_replace('/\D+/', '', $to) ?? '';
        if ($digits === '') {
            return false;
        }

        $response = Http::withToken($token)
            ->timeout(20)
            ->post("https://graph.facebook.com/v21.0/{$phoneNumberId}/messages", [
                'messaging_product' => 'whatsapp',
                'to' => $digits,
                'type' => 'text',
                'text' => ['body' => $message],
            ]);

        if ($response->successful()) {
            return true;
        }

        Log::error('cms.whatsapp.meta.failed', [
            'status' => $response->status(),
        ]);

        return false;
    }
}
