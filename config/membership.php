<?php

declare(strict_types=1);

return [
  /*
  |--------------------------------------------------------------------------
  | Membership number format
  |--------------------------------------------------------------------------
  |
  | Placeholders: {prefix}, {year}, {sequence}
  | Example: MM-2026-000001
  |
  */
  'number' => [
    'prefix' => env('MEMBERSHIP_NUMBER_PREFIX', 'MM'),
    'include_year' => env('MEMBERSHIP_NUMBER_INCLUDE_YEAR', true),
    'sequence_padding' => (int) env('MEMBERSHIP_NUMBER_PADDING', 6),
    'format' => env('MEMBERSHIP_NUMBER_FORMAT', '{prefix}-{year}-{sequence}'),
  ],

  'default_status' => 'application_submitted',
];
