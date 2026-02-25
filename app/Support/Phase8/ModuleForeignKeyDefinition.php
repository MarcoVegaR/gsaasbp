<?php

declare(strict_types=1);

namespace App\Support\Phase8;

final readonly class ModuleForeignKeyDefinition
{
    public function __construct(
        public string $column,
        public string $referencesTable,
        public string $referencesColumn,
        public string $scope,
        public string $onDelete,
    ) {}

    public function cascadesOnDelete(): bool
    {
        return $this->onDelete === 'cascade';
    }

    public function isTenantScoped(): bool
    {
        return $this->scope === 'tenant';
    }
}
