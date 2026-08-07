<?php

declare(strict_types=1);

return [
  'notifications' => [
    'admin_inbox_email' => env('CMS_ADMIN_INBOX_EMAIL', env('MAIL_FROM_ADDRESS')),
    'sms_enabled' => (bool) env('CMS_SMS_ENABLED', false),
    'whatsapp_enabled' => (bool) env('CMS_WHATSAPP_ENABLED', false),
  ],
];
