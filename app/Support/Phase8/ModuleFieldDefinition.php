<?php

declare(strict_types=1);

namespace App\Support\Phase8;

final readonly class ModuleFieldDefinition
{
    public function __construct(
        public string $name,
        public string $type,
        public bool $nullable,
        public bool $unique,
        public bool $audit,
        public bool $pii,
        public bool $secret,
    ) {}
}
