<?php

use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Laravel\Sanctum\Http\Middleware\AuthenticateSession;
use Laravel\Sanctum\Sanctum;

$parseExtraStateful = static function (string $value): array {
    return array_values(array_filter(array_map(
        static fn (string $item): string => trim($item),
        explode(',', $value),
    )));
};

$appHost = ltrim(Sanctum::currentApplicationUrlWithPort(), ',');

$statefulDomains = array_values(array_unique(array_filter(array_merge(
    config('app-frontend.stateful_domains', []),
    $parseExtraStateful((string) env('SANCTUM_STATEFUL_DOMAINS_EXTRA', '127.0.0.1:8000,::1')),
    $appHost !== '' ? [$appHost] : [],
))));

return [

    /*
    |--------------------------------------------------------------------------
    | Stateful Domains
    |--------------------------------------------------------------------------
    |
    | Hosts derived from FRONTEND_ORIGINS (config app-frontend) plus optional
    | SANCTUM_STATEFUL_DOMAINS_EXTRA for API self-requests and IPv6 loopback.
    |
    */

    'stateful' => $statefulDomains,

    /*
    |--------------------------------------------------------------------------
    | Sanctum Guards
    |--------------------------------------------------------------------------
    */

    'guard' => ['web'],

    /*
    |--------------------------------------------------------------------------
    | Expiration Minutes
    |--------------------------------------------------------------------------
    */

    'expiration' => null,

    /*
    |--------------------------------------------------------------------------
    | Token Prefix
    |--------------------------------------------------------------------------
    */

    'token_prefix' => env('SANCTUM_TOKEN_PREFIX', ''),

    /*
    |--------------------------------------------------------------------------
    | Sanctum Middleware
    |--------------------------------------------------------------------------
    */

    'middleware' => [
        'authenticate_session' => AuthenticateSession::class,
        'encrypt_cookies' => EncryptCookies::class,
        'validate_csrf_token' => ValidateCsrfToken::class,
    ],

];
