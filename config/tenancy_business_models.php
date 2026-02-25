<?php

declare(strict_types=1);

use App\Models\TenantNote;

$phase8ModulesConfig = is_file(__DIR__.'/phase8_modules.php')
    ? require __DIR__.'/phase8_modules.php'
    : [];

$generatedBusinessModels = array_values(array_filter(array_map(
    static fn (mixed $modelClass): string => trim((string) $modelClass),
    (array) (($phase8ModulesConfig['business_models'] ?? []) ?: []),
), static fn (string $modelClass): bool => $modelClass !== ''));

return array_values(array_unique([
    TenantNote::class,
    ...$generatedBusinessModels,
]));
