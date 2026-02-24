<?php

$rawHmacKeys = json_decode((string) env('PHASE4_HMAC_KEYS', '{"kid-1":"local-phase4-hmac-key"}'), true);

if (! is_array($rawHmacKeys)) {
    $rawHmacKeys = [];
}

$hmacKeys = [];

foreach ($rawHmacKeys as $kid => $key) {
    if (! is_string($kid) || $kid === '' || ! is_string($key) || trim($key) === '') {
        continue;
    }

    $hmacKeys[$kid] = trim($key);
}

if ($hmacKeys === []) {
    $hmacKeys['kid-1'] = 'local-phase4-hmac-key';
}

$activeKid = (string) env('PHASE4_HMAC_ACTIVE_KID', array_key_first($hmacKeys) ?: 'kid-1');

if (! array_key_exists($activeKid, $hmacKeys)) {
    $activeKid = array_key_first($hmacKeys) ?: 'kid-1';
}

return [
    's2s_events' => [
        'shared_secret' => (string) env('PHASE4_S2S_SHARED_SECRET', 'local-phase4-s2s-shared-secret'),
    ],

    'profile_projection' => [
        'ttl_seconds' => (int) env('PHASE4_PROFILE_TTL_SECONDS', 3600),
    ],

    'hmac' => [
        'active_kid' => $activeKid,
        'keys' => $hmacKeys,
        'denylist_fields' => [
            'password',
            'token',
            'authorization',
            'secret',
            'card_number',
            'raw_payload',
        ],
        'hmac_fields' => [
            'email',
            'phone',
            'card',
            'card_number',
            'token',
            'authorization',
        ],
        'max_string_length' => (int) env('PHASE4_LOG_MAX_STRING_LENGTH', 512),
    ],

    'invites' => [
        'soft_limit_per_minute' => (int) env('PHASE4_INVITES_SOFT_LIMIT_PER_MINUTE', 5),
        'ttl_minutes' => (int) env('PHASE4_INVITE_TTL_MINUTES', 1440),
        'queue' => (string) env('PHASE4_INVITES_QUEUE', 'default'),
    ],

    'rbac' => [
        'step_up_ttl_seconds' => (int) env('PHASE4_RBAC_STEP_UP_TTL_SECONDS', 900),
        'owner_role' => (string) env('PHASE4_RBAC_OWNER_ROLE', 'owner'),
        'non_delegable_permissions' => array_values(array_filter(array_map(
            static fn (string $permission): string => trim($permission),
            explode(',', (string) env('PHASE4_RBAC_NON_DELEGABLE_PERMISSIONS', 'billing.manage,tenant.transfer_ownership')),
        ), static fn (string $permission): bool => $permission !== '')),
    ],

    'audit' => [
        'export_disk' => (string) env('PHASE4_AUDIT_EXPORT_DISK', 'local'),
        'default_window_hours' => (int) env('PHASE4_AUDIT_DEFAULT_WINDOW_HOURS', 24),
    ],

    'entitlements' => [
        'default_granted' => (bool) env('PHASE4_ENTITLEMENTS_DEFAULT_GRANTED', false),
    ],
];
