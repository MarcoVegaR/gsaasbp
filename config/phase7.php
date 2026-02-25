<?php

declare(strict_types=1);

return [
    'admin' => [
        'inactivity_timeout_seconds' => (int) env('PHASE7_ADMIN_INACTIVITY_TIMEOUT_SECONDS', 900),
        'mutation_origin_required' => (bool) env('PHASE7_ADMIN_MUTATION_ORIGIN_REQUIRED', true),
        'blocked_query_keys' => array_values(array_filter(array_map(
            static fn (string $key): string => strtolower(trim($key)),
            explode(',', (string) env('PHASE7_ADMIN_BLOCKED_QUERY_KEYS', 'phpsessid,session_id,token,access_token,download_token')),
        ), static fn (string $key): bool => $key !== '')),
    ],

    'system_context' => [
        'allowed_purposes' => array_values(array_filter(array_map(
            static fn (string $purpose): string => trim($purpose),
            explode(',', (string) env('PHASE7_SYSTEM_CONTEXT_ALLOWED_PURPOSES', implode(',', [
                'admin.tenants.list',
                'admin.tenant.status.update',
                'admin.tenant.hard-delete.approval',
                'admin.tenant.hard-delete.execute',
                'admin.audit.logs.read',
                'admin.audit.export.generate',
                'admin.billing.events.read',
                'admin.billing.reconcile.dispatch',
                'admin.impersonation.issue',
                'admin.impersonation.revoke',
            ]))),
        ), static fn (string $purpose): bool => $purpose !== '')),
        'purpose_max_limits' => [
            'admin.tenants.list' => 200,
            'admin.audit.logs.read' => 500,
            'admin.audit.export.generate' => 5000,
            'admin.billing.events.read' => 500,
        ],
    ],

    'telemetry' => [
        'privacy_budget' => [
            'cache_store' => (string) env('PHASE7_PRIVACY_BUDGET_CACHE_STORE', 'array'),
            'window_seconds' => (int) env('PHASE7_PRIVACY_BUDGET_WINDOW_SECONDS', 3600),
            'max_cost_per_window' => (int) env('PHASE7_PRIVACY_BUDGET_MAX_COST_PER_WINDOW', 20),
        ],
    ],

    'forensics' => [
        'max_window_days' => (int) env('PHASE7_FORENSICS_MAX_WINDOW_DAYS', 30),
        'max_export_rows' => (int) env('PHASE7_FORENSICS_MAX_EXPORT_ROWS', 5000),
        'export_disk' => (string) env('PHASE7_FORENSICS_EXPORT_DISK', 'local'),
        'export_token_ttl_seconds' => (int) env('PHASE7_FORENSICS_EXPORT_TOKEN_TTL_SECONDS', 300),
    ],

    'hard_delete' => [
        'approval_ttl_seconds' => (int) env('PHASE7_HARD_DELETE_APPROVAL_TTL_SECONDS', 900),
        'signature_key' => (string) env('PHASE7_HARD_DELETE_SIGNATURE_KEY', 'phase7-hard-delete-secret'),
    ],

    'impersonation' => [
        'ttl_seconds' => (int) env('PHASE7_IMPERSONATION_TTL_SECONDS', 180),
        'cache_store' => (string) env('PHASE7_IMPERSONATION_CACHE_STORE', 'array'),
        'allowlist_termination_routes' => [
            'tenant.phase7.impersonation.terminate',
        ],
    ],
];
