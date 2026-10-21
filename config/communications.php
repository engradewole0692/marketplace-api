<?php

declare(strict_types=1);

return [
    // log | twilio | termii
    'sms_provider' => env('SMS_PROVIDER', 'log'),
    // log | twilio | meta
    'whatsapp_provider' => env('WHATSAPP_PROVIDER', 'log'),

    // ISO 3166-1 alpha-2 region used only to parse national-format numbers when the record has no country.
    'default_phone_region' => env('COMMUNICATIONS_DEFAULT_PHONE_REGION', 'NG'),
    'timezone' => env('COMMUNICATIONS_TIMEZONE', 'Africa/Lagos'),

    'twilio' => [
        'account_sid' => env('TWILIO_ACCOUNT_SID'),
        'auth_token' => env('TWILIO_AUTH_TOKEN'),
        'from' => env('TWILIO_FROM'),
        'messaging_service_sid' => env('TWILIO_MESSAGING_SERVICE_SID'),
        'whatsapp_from' => env('TWILIO_WHATSAPP_FROM'),
        // Public URL Twilio signs and posts status callbacks to. Defaults to APP_URL + route.
        'webhook_url' => env('TWILIO_WEBHOOK_URL'),
    ],

    'termii' => [
        'api_key' => env('TERMII_API_KEY'),
        'secret_key' => env('TERMII_SECRET_KEY'),
        'sender_id' => env('TERMII_SENDER_ID'),
        'base_url' => env('TERMII_BASE_URL', 'https://api.ng.termii.com'),
        // generic for promotional traffic; dnd route is only used for transactional messages when enabled.
        'transactional_dnd_route' => (bool) env('TERMII_TRANSACTIONAL_DND_ROUTE', false),
    ],

    'meta_whatsapp' => [
        'token' => env('WHATSAPP_ACCESS_TOKEN'),
        'phone_number_id' => env('WHATSAPP_PHONE_NUMBER_ID'),
        'business_account_id' => env('WHATSAPP_BUSINESS_ACCOUNT_ID'),
        'app_secret' => env('WHATSAPP_APP_SECRET'),
        'verify_token' => env('WHATSAPP_WEBHOOK_VERIFY_TOKEN'),
        'graph_version' => env('WHATSAPP_GRAPH_VERSION', 'v21.0'),
    ],

    'costs' => [
        'currency' => env('COMMUNICATIONS_COST_CURRENCY', 'NGN'),
        'sms_per_segment' => env('SMS_COST_PER_SEGMENT'),
        'whatsapp_per_message' => env('WHATSAPP_COST_PER_MESSAGE'),
    ],

    'campaigns' => [
        'batch_size' => (int) env('COMMUNICATIONS_BATCH_SIZE', 50),
        'rate_per_minute' => [
            'email' => (int) env('COMMUNICATIONS_EMAIL_RATE_PER_MINUTE', 120),
            'sms' => (int) env('COMMUNICATIONS_SMS_RATE_PER_MINUTE', 60),
            'whatsapp' => (int) env('COMMUNICATIONS_WHATSAPP_RATE_PER_MINUTE', 60),
        ],
        'max_attempts' => (int) env('COMMUNICATIONS_MAX_ATTEMPTS', 3),
        // Sends at or above this many messages require an explicit typed confirmation in the composer.
        'confirmation_threshold' => (int) env('COMMUNICATIONS_CONFIRMATION_THRESHOLD', 100),
        'queue' => env('COMMUNICATIONS_QUEUE', 'default'),
    ],

    'consent' => [
        // Meta policy: business-initiated WhatsApp messages require recorded opt-in.
        'whatsapp_requires_opt_in' => (bool) env('COMMUNICATIONS_WHATSAPP_REQUIRES_OPT_IN', true),
        'sms_promotional_requires_opt_in' => (bool) env('COMMUNICATIONS_SMS_PROMOTIONAL_REQUIRES_OPT_IN', false),
    ],

    // Modules that hold sensitive data need an extra permission on top of communications access.
    'restricted_modules' => [
        'counseling' => ['counselling.manage'],
        'prayer' => ['prayer.manage', 'cms.manage', 'settings.manage'],
    ],
];
