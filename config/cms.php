<?php

declare(strict_types=1);

return [
  'notifications' => [
    'admin_inbox_email' => env('CMS_ADMIN_INBOX_EMAIL', env('ADMIN_EMAIL', env('COMPANY_EMAIL', env('MAIL_FROM_ADDRESS')))),
    'company_email' => env('COMPANY_EMAIL', env('ADMIN_EMAIL', env('CMS_ADMIN_INBOX_EMAIL', env('MAIL_FROM_ADDRESS')))),
    'reply_to_email' => env('REPLY_TO_EMAIL', env('MAIL_REPLY_TO_ADDRESS', env('MAIL_FROM_ADDRESS'))),
    'sms_enabled' => (bool) env('CMS_SMS_ENABLED', false),
    'whatsapp_enabled' => (bool) env('CMS_WHATSAPP_ENABLED', false),
  ],
];
