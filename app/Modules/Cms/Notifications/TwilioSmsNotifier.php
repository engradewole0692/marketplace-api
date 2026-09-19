<?php

declare(strict_types=1);

namespace App\Modules\Cms\Notifications;

use App\Modules\Cms\Contracts\SmsNotifierContract;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

final class TwilioSmsNotifier implements SmsNotifierContract
{
    public function send(string $to, string $message, array $context = []): bool
    {
        $sid = (string) config('communications.twilio.account_sid');
        $token = (string) config('communications.twilio.auth_token');
        $from = (string) config('communications.twilio.from');
        if ($sid === '' || $token === '' || $from === '') {
            Log::warning('cms.sms.twilio.unconfigured');

            return false;
        }

        $response = Http::asForm()
            ->timeout(20)
            ->withBasicAuth($sid, $token)
            ->post("https://api.twilio.com/2010-04-01/Accounts/{$sid}/Messages.json", [
                'To' => $to,
                'From' => $from,
                'Body' => $message,
            ]);

        if ($response->successful()) {
            return true;
        }

        Log::error('cms.sms.twilio.failed', [
            'status' => $response->status(),
        ]);

        return false;
    }
}
