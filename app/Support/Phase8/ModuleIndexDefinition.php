<?php

declare(strict_types=1);

namespace App\Support\Phase8;

final readonly class ModuleIndexDefinition
{
    /**
     * @param  list<string>  $columns
     */
    public function __construct(
        public string $type,
        public array $columns,
        public ?string $name = null,
    ) {}

    public function isUnique(): bool
    {
        return $this->type === 'unique';
    }
}
