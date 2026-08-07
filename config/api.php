<?php

declare(strict_types=1);

return [

  /*
  |--------------------------------------------------------------------------
  | API Versioning
  |--------------------------------------------------------------------------
  |
  | The default API version prefix used for all versioned routes.
  |
  */

  'version' => env('API_VERSION', 'v1'),

  'prefix' => env('API_PREFIX', 'api'),

  /*
  |--------------------------------------------------------------------------
  | Response Meta
  |--------------------------------------------------------------------------
  */

  'meta' => [
    'include_request_id' => env('API_INCLUDE_REQUEST_ID', true),
    'include_timestamp' => env('API_INCLUDE_TIMESTAMP', true),
  ],

];
