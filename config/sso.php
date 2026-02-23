<?php

declare(strict_types=1);

$rawS2sClients = json_decode((string) env('SSO_S2S_CLIENTS', '{}'), true);

if (! is_array($rawS2sClients)) {
    $rawS2sClients = [];
}

$s2sClients = [];

foreach ($rawS2sClients as $token => $client) {
    if (! is_string($token) || $token === '' || ! is_array($client)) {
        continue;
    }

    $tenantId = $client['tenant_id'] ?? null;
    $caller = $client['caller'] ?? null;

    if (! is_string($tenantId) || $tenantId === '' || ! is_string($caller) || $caller === '') {
        continue;
    }

    $s2sClients[$token] = [
        'tenant_id' => $tenantId,
        'caller' => $caller,
    ];
}

$rawPublicKeys = json_decode((string) env('SSO_JWT_PUBLIC_KEYS', '{}'), true);

if (! is_array($rawPublicKeys)) {
    $rawPublicKeys = [];
}

$publicKeys = [];

foreach ($rawPublicKeys as $kid => $key) {
    if (! is_string($kid) || $kid === '' || ! is_string($key) || trim($key) === '') {
        continue;
    }

    $publicKeys[$kid] = trim($key);
}

$defaultKid = (string) env('SSO_JWT_KID', 'local-kid-1');
$defaultPublicKey = trim((string) env('SSO_JWT_PUBLIC_KEY', ''));

if ($defaultPublicKey !== '' && ! isset($publicKeys[$defaultKid])) {
    $publicKeys[$defaultKid] = $defaultPublicKey;
}

$allowedKids = array_values(array_unique(array_filter(array_map(
    static fn (string $kid): string => trim($kid),
    explode(',', (string) env('SSO_JWT_ALLOWED_KIDS', $defaultKid)),
), static fn (string $kid): bool => $kid !== '')));

$trustedPlatformDomains = array_values(array_unique(array_filter(array_map(
    static fn (string $domain): string => trim($domain),
    explode(',', (string) env('SSO_TRUSTED_PLATFORM_DOMAINS', implode(',', config('tenancy.central_domains', [])))),
), static fn (string $domain): bool => $domain !== '')));

return [
    'mode' => env('SSO_MODE', 'backchannel'),

    'issuer' => env('SSO_ISSUER', rtrim((string) config('app.url', 'http://localhost'), '/')),
    'audience' => env('SSO_AUDIENCE', 'tenant-sso'),

    'assertion_ttl_seconds' => (int) env('SSO_ASSERTION_TTL_SECONDS', 30),
    'backchannel_code_ttl_seconds' => (int) env('SSO_BACKCHANNEL_CODE_TTL_SECONDS', 30),

    'jwt' => [
        'algorithm' => env('SSO_JWT_ALGORITHM', 'RS256'),
        'typ' => env('SSO_JWT_TYP', 'JWT'),
        'kid' => $defaultKid,
        'allowed_kids' => $allowedKids,
        'clock_skew_seconds' => (int) env('SSO_JWT_CLOCK_SKEW_SECONDS', 60),
        'private_key' => trim((string) env('SSO_JWT_PRIVATE_KEY', '')),
        'public_keys' => $publicKeys,
        'allowed_crit_headers' => array_values(array_unique(array_filter(array_map(
            static fn (string $crit): string => trim($crit),
            explode(',', (string) env('SSO_JWT_ALLOWED_CRIT_HEADERS', '')),
        ), static fn (string $crit): bool => $crit !== ''))),
    ],

    'claims' => [
        'version' => env('SSO_CLAIMS_VERSION', 'v1'),
        'cache_store' => env('SSO_CLAIMS_CACHE_STORE', 'array'),
        'quota_store' => env('SSO_CLAIMS_QUOTA_STORE', 'array'),
        'cache_ttl_seconds' => (int) env('SSO_CLAIMS_CACHE_TTL_SECONDS', 60),
        'rate_limit_per_minute' => (int) env('SSO_CLAIMS_RATE_LIMIT_PER_MINUTE', 60),
        'daily_quota' => (int) env('SSO_CLAIMS_DAILY_QUOTA', 1000),
        'weekly_quota' => (int) env('SSO_CLAIMS_WEEKLY_QUOTA', 5000),
        'alarm_hit_ratio_threshold' => (float) env('SSO_CLAIMS_ALARM_HIT_RATIO_THRESHOLD', 0.98),
        'alarm_miss_spike_threshold' => (int) env('SSO_CLAIMS_ALARM_MISS_SPIKE_THRESHOLD', 100),
    ],

    'token_store' => env('SSO_TOKEN_STORE', 'array'),

    'redis' => [
        'write_connection' => env('SSO_REDIS_WRITE_CONNECTION', 'sso_write'),
        'write_role' => env('SSO_REDIS_WRITE_ROLE', 'primary'),
    ],

    's2s' => [
        'header' => env('SSO_S2S_HEADER', 'X-S2S-Key'),
        'clients' => $s2sClients,
    ],

    'security' => [
        'trusted_platform_domains' => $trustedPlatformDomains,
        'enable_hsts_preload' => (bool) env('SSO_ENABLE_HSTS_PRELOAD', false),
        'hsts_max_age' => (int) env('SSO_HSTS_MAX_AGE', 63072000),
    ],
];
