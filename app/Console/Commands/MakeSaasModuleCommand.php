<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Phase8\ModuleGenerationService;
use Illuminate\Console\Command;
use Throwable;

final class MakeSaasModuleCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'make:saas-module
        {name : Studly module name}
        {--schema= : Path to module schema file (YAML/JSON)}';

    /**
     * @var string
     */
    protected $description = 'Generate a secure tenant module stack with atomic rollback and static linting.';

    public function __construct(
        private readonly ModuleGenerationService $generationService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $name = trim((string) $this->argument('name'));
        $schemaPath = $this->option('schema');

        try {
            $result = $this->generationService->generate(
                name: $name,
                schemaPath: is_string($schemaPath) ? $schemaPath : null,
            );
        } catch (Throwable $throwable) {
            $this->components->error($throwable->getMessage());

            return self::FAILURE;
        }

        $this->components->info(sprintf(
            'Phase 8 module [%s] generated successfully.',
            $result->module->slug,
        ));

        $this->line(sprintf('Created files: %d', count($result->createdFiles)));
        $this->line(sprintf('Updated files: %d', count($result->updatedFiles)));

        return self::SUCCESS;
    }
}
