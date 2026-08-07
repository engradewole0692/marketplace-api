<?php

declare(strict_types=1);

use App\Enums\AuthGuardName;
use App\Models\User;

return [

  'defaults' => [
    'guard' => env('AUTH_GUARD', 'web'),
    'passwords' => env('AUTH_PASSWORD_BROKER', 'users'),
  ],

  'guards' => [
    'web' => [
      'driver' => 'session',
      'provider' => 'users',
    ],
    'admin' => [
      'driver' => 'session',
      'provider' => 'users',
    ],
    AuthGuardName::SuperAdministrator->value => [
      'driver' => 'session',
      'provider' => 'users',
    ],
    AuthGuardName::Administrator->value => [
      'driver' => 'session',
      'provider' => 'users',
    ],
    AuthGuardName::Leader->value => [
      'driver' => 'session',
      'provider' => 'users',
    ],
    AuthGuardName::Instructor->value => [
      'driver' => 'session',
      'provider' => 'users',
    ],
    AuthGuardName::Member->value => [
      'driver' => 'session',
      'provider' => 'users',
    ],
  ],

  'providers' => [
    'users' => [
      'driver' => 'eloquent',
      'model' => env('AUTH_MODEL', User::class),
    ],
  ],

  'passwords' => [
    'users' => [
      'provider' => 'users',
      'table' => env('AUTH_PASSWORD_RESET_TOKEN_TABLE', 'password_reset_tokens'),
      'expire' => 60,
      'throttle' => 60,
    ],
  ],

  'password_timeout' => env('AUTH_PASSWORD_TIMEOUT', 10800),

];
