<?php

declare(strict_types=1);

return [
    'sms_provider' => env('SMS_PROVIDER', 'log'),
    'whatsapp_provider' => env('WHATSAPP_PROVIDER', 'log'),
    'twilio' => [
        'account_sid' => env('TWILIO_ACCOUNT_SID'),
        'auth_token' => env('TWILIO_AUTH_TOKEN'),
        'from' => env('TWILIO_FROM'),
        'whatsapp_from' => env('TWILIO_WHATSAPP_FROM'),
    ],
    'meta_whatsapp' => [
        'token' => env('WHATSAPP_ACCESS_TOKEN'),
        'phone_number_id' => env('WHATSAPP_PHONE_NUMBER_ID'),
    ],
];
