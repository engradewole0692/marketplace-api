<?php

declare(strict_types=1);

/**
 * Single source of truth for SPA frontend origins.
 *
 * CORS allowed origins and Sanctum stateful domains are derived from the same
 * list so local dev hostnames (localhost vs 127.0.0.1) never drift apart.
 *
 * Configure via FRONTEND_ORIGINS (comma-separated full URLs with scheme).
 * Legacy CORS_ALLOWED_ORIGINS / FRONTEND_URL are still honoured when unset.
 *
 * Localhost origins are merged only when APP_ENV is local/testing so production
 * does not advertise developer origins to CORS/Sanctum.
 */

$defaultLocalOrigins = [
    'http://localhost:8080',
    'http://127.0.0.1:8080',
    'http://localhost:8081',
    'http://127.0.0.1:8081',
    'http://localhost:8082',
    'http://127.0.0.1:8082',
    'http://localhost:3000',
    'http://127.0.0.1:3000',
    'http://localhost:5173',
    'http://127.0.0.1:5173',
];

$parseList = static function (string $value): array {
    return array_values(array_filter(array_map(
        static fn (string $item): string => trim($item),
        explode(',', $value),
    )));
};

$appEnv = (string) env('APP_ENV', 'production');
$includeLocalOrigins = in_array($appEnv, ['local', 'testing'], true);

$configuredOrigins = env('FRONTEND_ORIGINS');
if (is_string($configuredOrigins) && $configuredOrigins !== '') {
    $origins = $parseList($configuredOrigins);
} else {
    $legacyCors = env('CORS_ALLOWED_ORIGINS');
    if (is_string($legacyCors) && $legacyCors !== '') {
        $origins = $parseList($legacyCors);
    } else {
        $frontendUrl = (string) env('FRONTEND_URL', 'http://localhost:8081');
        $origins = $parseList($frontendUrl);
        if ($includeLocalOrigins) {
            $origins = array_merge($origins, $defaultLocalOrigins);
        }
    }
}

if ($includeLocalOrigins) {
    $origins = array_values(array_unique(array_merge($origins, $defaultLocalOrigins)));
} else {
    $origins = array_values(array_unique($origins));
}

$statefulDomains = array_values(array_unique(array_filter(array_map(
    static function (string $origin): ?string {
        $parts = parse_url($origin);
        if (! isset($parts['host'])) {
            return null;
        }

        $host = $parts['host'];
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';

        return $host.$port;
    },
    $origins,
))));

return [
    'origins' => $origins,
    'stateful_domains' => $statefulDomains,
    'url' => $origins[0] ?? (string) env('FRONTEND_URL', 'http://localhost:8081'),
];
