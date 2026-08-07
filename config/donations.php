<?php

declare(strict_types=1);

return [
  'finance_email' => env('DONATIONS_FINANCE_EMAIL', env('MAIL_FROM_ADDRESS')),
  'checkout_base_url' => env('FRONTEND_URL', env('APP_URL', 'http://localhost:5173')),
  'crypto_enabled' => (bool) env('DONATIONS_CRYPTO_ENABLED', false),
];
