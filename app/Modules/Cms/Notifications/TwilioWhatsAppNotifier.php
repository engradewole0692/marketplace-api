<?php

declare(strict_types=1);

namespace App\Modules\Cms\Notifications;

use App\Modules\Cms\Contracts\WhatsAppNotifierContract;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

final class TwilioWhatsAppNotifier implements WhatsAppNotifierContract
{
    public function send(string $to, string $message, array $context = []): bool
    {
        $sid = (string) config('communications.twilio.account_sid');
        $token = (string) config('communications.twilio.auth_token');
        $from = (string) config('communications.twilio.whatsapp_from');
        if ($sid === '' || $token === '' || $from === '') {
            Log::warning('cms.whatsapp.twilio.unconfigured');

            return false;
        }

        $toAddress = str_starts_with(strtolower($to), 'whatsapp:') ? $to : 'whatsapp:'.$to;
        $fromAddress = str_starts_with(strtolower($from), 'whatsapp:') ? $from : 'whatsapp:'.$from;

        $response = Http::asForm()
            ->timeout(20)
            ->withBasicAuth($sid, $token)
            ->post("https://api.twilio.com/2010-04-01/Accounts/{$sid}/Messages.json", [
                'To' => $toAddress,
                'From' => $fromAddress,
                'Body' => $message,
            ]);

        if ($response->successful()) {
            return true;
        }

        Log::error('cms.whatsapp.twilio.failed', [
            'status' => $response->status(),
        ]);

        return false;
    }
}
