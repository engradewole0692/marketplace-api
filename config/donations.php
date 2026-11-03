<?php

declare(strict_types=1);

return [
  'finance_email' => env('DONATIONS_FINANCE_EMAIL', env('MAIL_FROM_ADDRESS')),
  'checkout_base_url' => env('FRONTEND_URL', env('APP_URL', 'http://localhost:5173')),
  'crypto_enabled' => (bool) env('DONATIONS_CRYPTO_ENABLED', false),
  // Methods that can run without a country (one global account, e.g. PayPal Orders API).
  'global_methods' => ['paypal'],
  // School enrollment is paid through the global PayPal checkout only; country bank accounts are donation-only.
  'school_enrollment_methods' => ['paypal'],
];
