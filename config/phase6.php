<?php

declare(strict_types=1);

$allowedOrigins = array_values(array_unique(array_filter(array_map(
    static fn (string $value): string => trim($value),
    explode(',', (string) env('PHASE6_ALLOWED_ORIGINS', 'http://localhost,http://127.0.0.1,http://tenant.localhost')),
), static fn (string $value): bool => $value !== '')));

return [
    'allowed_origins' => $allowedOrigins,

    'auth' => [
        'cache_store' => (string) env('PHASE6_AUTH_CACHE_STORE', env('CACHE_STORE', 'array')),
        'uniform_deny_latency_ms' => (int) env('PHASE6_AUTH_UNIFORM_DENY_LATENCY_MS', 35),
        'session_reauth_ttl_seconds' => (int) env('PHASE6_AUTH_REAUTH_TTL_SECONDS', 600),
        'max_connection_age_seconds' => (int) env('PHASE6_MAX_CONNECTION_AGE_SECONDS', 900),
        'rate_limit_per_minute' => (int) env('PHASE6_AUTH_RATE_LIMIT_PER_MINUTE', 90),
        'reconnect_rate_limit_per_minute' => (int) env('PHASE6_AUTH_RECONNECT_RATE_LIMIT_PER_MINUTE', 180),
        'max_connections_per_user' => (int) env('PHASE6_MAX_CONNECTIONS_PER_USER', 3),
        'max_subscriptions_per_socket' => (int) env('PHASE6_MAX_SUBSCRIPTIONS_PER_SOCKET', 50),
        'max_subscribe_events_per_minute' => (int) env('PHASE6_MAX_SUBSCRIBE_EVENTS_PER_MINUTE', 120),
    ],

    'ws' => [
        'max_request_size' => (int) env('PHASE6_WS_MAX_REQUEST_SIZE', 32768),
        'max_message_size' => (int) env('PHASE6_WS_MAX_MESSAGE_SIZE', 16384),
        'idle_timeout_seconds' => (int) env('PHASE6_WS_IDLE_TIMEOUT_SECONDS', 75),
        'ping_interval_seconds' => (int) env('PHASE6_WS_PING_INTERVAL_SECONDS', 25),
        'max_channel_name_length' => (int) env('PHASE6_WS_MAX_CHANNEL_NAME_LENGTH', 180),
    ],

    'notifications' => [
        'retention_days' => (int) env('PHASE6_NOTIFICATIONS_RETENTION_DAYS', 30),
        'list_limit' => (int) env('PHASE6_NOTIFICATIONS_LIST_LIMIT', 50),
        'prune_chunk_size' => (int) env('PHASE6_NOTIFICATIONS_PRUNE_CHUNK_SIZE', 500),
    ],

    'outbox' => [
        'default_queue' => (string) env('PHASE6_OUTBOX_QUEUE', 'default'),
        'prune_after_hours' => (int) env('PHASE6_OUTBOX_PRUNE_AFTER_HOURS', 168),
    ],

    'realtime' => [
        'force_degraded' => (bool) env('PHASE6_REALTIME_FORCE_DEGRADED', false),
        'cache_store' => (string) env('PHASE6_REALTIME_CACHE_STORE', env('CACHE_STORE', 'array')),
        'degraded_ttl_seconds' => (int) env('PHASE6_REALTIME_DEGRADED_TTL_SECONDS', 60),
        'polling_interval_seconds' => (int) env('PHASE6_POLLING_INTERVAL_SECONDS', 20),
    ],

    'telemetry' => [
        'fingerprint_hmac_kid' => (string) env('PHASE6_TENANT_FINGERPRINT_HMAC_KID', 'phase6-default-kid'),
        'fingerprint_hmac_key' => (string) env('PHASE6_TENANT_FINGERPRINT_HMAC_KEY', (string) env('APP_KEY', 'phase6-fallback-key')),
        'log_channel' => (string) env('PHASE6_TELEMETRY_LOG_CHANNEL', env('LOG_CHANNEL', 'stack')),
    ],
];
