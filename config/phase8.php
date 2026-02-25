<?php

declare(strict_types=1);

$rootPath = trim((string) env('PHASE8_GENERATOR_ROOT_PATH', ''));
$storagePath = trim((string) env('PHASE8_GENERATOR_STORAGE_PATH', ''));

if ($rootPath === '') {
    $rootPath = base_path();
}

if ($storagePath === '') {
    $storagePath = storage_path('framework/module-generator');
}

return [
    'generator' => [
        'root_path' => $rootPath,
        'staging_root' => $storagePath,
        'lock_file' => $storagePath.'/.phase8.lock',
        'default_schema_file' => 'module.yml',
        'lock_timeout_seconds' => (int) env('PHASE8_LOCK_TIMEOUT_SECONDS', 30),
        'test_lock_hold_ms' => (int) env('PHASE8_TEST_LOCK_HOLD_MS', 0),
        'test_fail_after_replace' => (bool) env('PHASE8_TEST_FAIL_AFTER_REPLACE', false),
    ],

    'parser' => [
        'allowed_field_types' => [
            'string',
            'text',
            'integer',
            'boolean',
            'uuid',
            'json',
            'date',
            'datetime',
            'timestamp',
        ],
    ],

    'linter' => [
        'forbidden_snippets' => [
            'DB::table(',
            'DB::select(',
            '->withoutGlobalScope(',
            '->withoutGlobalScopes(',
        ],
        'additional_forbidden_snippets' => array_values(array_filter(array_map(
            static fn (string $value): string => trim($value),
            explode(',', (string) env('PHASE8_LINTER_EXTRA_FORBIDDEN_SNIPPETS', '')),
        ), static fn (string $value): bool => $value !== '')),
    ],
];
