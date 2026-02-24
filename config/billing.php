<?php

return [
    'default_provider' => (string) env('BILLING_DEFAULT_PROVIDER', 'local'),

    'providers' => [
        'local' => [
            'driver' => App\Support\Billing\Providers\ConfigBillingProvider::class,
            'webhook_secret' => (string) env('BILLING_LOCAL_WEBHOOK_SECRET', 'local-billing-webhook-secret'),
            'signature_header' => (string) env('BILLING_LOCAL_SIGNATURE_HEADER', 'X-Billing-Signature'),
            'reconciliation_snapshots' => [],
        ],
    ],

    'reconciliation' => [
        'chunk_size' => (int) env('BILLING_RECONCILIATION_CHUNK_SIZE', 100),
        'backoff_seconds' => (int) env('BILLING_RECONCILIATION_BACKOFF_SECONDS', 30),
    ],
];
