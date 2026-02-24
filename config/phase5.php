<?php

declare(strict_types=1);

$superadminEmails = array_values(array_unique(array_filter(array_map(
    static fn (string $email): string => trim(strtolower($email)),
    explode(',', (string) env('SUPERADMIN_EMAILS', '')),
), static fn (string $email): bool => $email !== '')));

$allowedActorIssuers = array_values(array_unique(array_filter(array_map(
    static fn (string $issuer): string => trim($issuer),
    explode(',', (string) env('PHASE5_IMPERSONATION_ALLOWED_ISSUERS', (string) config('sso.issuer', 'http://localhost'))),
), static fn (string $issuer): bool => $issuer !== '')));

return [
    'superadmin' => [
        'emails' => $superadminEmails,
    ],

    'platform' => [
        'session_cookie' => (string) env('PHASE5_PLATFORM_SESSION_COOKIE', '__Host-platform_session'),
        'session_same_site' => (string) env('PHASE5_PLATFORM_SESSION_SAME_SITE', 'lax'),
    ],

    'step_up' => [
        'default_ttl_seconds' => (int) env('PHASE5_STEP_UP_TTL_SECONDS', 600),
        'hard_delete_strict_ip' => (bool) env('PHASE5_STEP_UP_HARD_DELETE_STRICT_IP', false),
        'allowed_scopes' => array_values(array_filter(array_map(
            static fn (string $scope): string => trim($scope),
            explode(',', (string) env('PHASE5_STEP_UP_ALLOWED_SCOPES', 'platform.tenants.hard-delete')),
        ), static fn (string $scope): bool => $scope !== '')),
    ],

    'tenant_status' => [
        'cache_store' => (string) env('PHASE5_TENANT_STATUS_CACHE_STORE', 'array'),
        'cache_ttl_seconds' => (int) env('PHASE5_TENANT_STATUS_CACHE_TTL_SECONDS', 15),
        'blocked_statuses' => [
            'suspended',
            'hard_deleted',
        ],
    ],

    'impersonation' => [
        'allowed_actor_issuers' => $allowedActorIssuers,
        'mutation_allowlist' => array_values(array_filter(array_map(
            static fn (string $routeName): string => trim($routeName),
            explode(',', (string) env('PHASE5_IMPERSONATION_MUTATION_ALLOWLIST', '')),
        ), static fn (string $routeName): bool => $routeName !== '')),
    ],

    'telemetry' => [
        'collector' => [
            'resource_allowlist' => [
                'service.name',
                'deployment.environment',
                'service.version',
            ],
            'span_attribute_allowlist' => [
                'http.method',
                'http.route',
                'http.status_code',
                'app.surface',
            ],
            'metric_attribute_allowlist' => [
                'metric.name',
                'metric.unit',
                'environment',
            ],
            'log_attribute_allowlist' => [
                'log.level',
                'event.name',
                'environment',
            ],
            'redacted_keys' => [
                'email',
                'user_id',
                'tenant_id',
                'ip',
                'ip_address',
                'path_params',
                'authorization',
                'token',
            ],
        ],

        'analytics' => [
            'cache_store' => (string) env('PHASE5_ANALYTICS_CACHE_STORE', 'array'),
            'cache_ttl_seconds' => (int) env('PHASE5_ANALYTICS_CACHE_TTL_SECONDS', 60),
            'rate_limit_per_minute' => (int) env('PHASE5_ANALYTICS_RATE_LIMIT_PER_MINUTE', 30),
            'k_anonymity' => (int) env('PHASE5_ANALYTICS_K_ANONYMITY', 10),
            'bucket_seconds' => (int) env('PHASE5_ANALYTICS_BUCKET_SECONDS', 3600),
            'contribution_cap_per_tenant' => (int) env('PHASE5_ANALYTICS_CONTRIBUTION_CAP_PER_TENANT', 10),
            'rounding_quantum' => (int) env('PHASE5_ANALYTICS_ROUNDING_QUANTUM', 5),
        ],
    ],
];
