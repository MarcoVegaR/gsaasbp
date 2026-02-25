<?php

declare(strict_types=1);

namespace App\Support\Phase8;

final readonly class ModuleGenerationResult
{
    /**
     * @param  list<string>  $createdFiles
     * @param  list<string>  $updatedFiles
     */
    public function __construct(
        public ModuleDefinition $module,
        public array $createdFiles,
        public array $updatedFiles,
    ) {}
}
