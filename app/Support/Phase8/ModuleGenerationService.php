<?php

declare(strict_types=1);

namespace App\Support\Phase8;

use RuntimeException;
use Throwable;

final class ModuleGenerationService
{
    public function __construct(
        private readonly ModuleSchemaParser $schemaParser,
        private readonly ModuleArtifactBuilder $artifactBuilder,
        private readonly GeneratedModuleLinter $linter,
        private readonly AtomicFileManager $fileManager,
        private readonly GeneratorLock $lock,
    ) {}

    public function generate(string $name, ?string $schemaPath = null): ModuleGenerationResult
    {
        return $this->lock->execute(function () use ($name, $schemaPath): ModuleGenerationResult {
            $rootPath = $this->resolveRootPath();
            $stagingRoot = $this->resolveStagingRoot();
            $this->assertSameFilesystem($rootPath, $stagingRoot);

            $module = $this->schemaParser->parse($name, $schemaPath);
            $generatedFileSet = $this->artifactBuilder->build($module, $rootPath);

            $stagingDirectory = $stagingRoot.DIRECTORY_SEPARATOR.bin2hex(random_bytes(12));

            $this->fileManager->ensureDirectory($stagingDirectory);

            $createdFiles = [];
            $updatedFiles = [];
            $replacedBackups = [];
            $movedFiles = [];

            try {
                foreach ($generatedFileSet->newFiles as $relativePath => $contents) {
                    $stagingPath = $stagingDirectory.DIRECTORY_SEPARATOR.$relativePath;
                    $this->fileManager->atomicReplace($stagingPath, $contents);
                    $createdFiles[] = $relativePath;
                }

                $this->linter->lint(
                    phpFiles: $this->collectByExtension($generatedFileSet, ['php']),
                    tsFiles: $this->collectByExtension($generatedFileSet, ['ts', 'tsx']),
                );

                foreach ($generatedFileSet->replacements as $relativePath => $contents) {
                    $targetPath = $rootPath.DIRECTORY_SEPARATOR.$relativePath;
                    $replacedBackups[$targetPath] = is_file($targetPath)
                        ? (string) file_get_contents($targetPath)
                        : null;

                    $this->fileManager->atomicReplace($targetPath, $contents);
                    $updatedFiles[] = $relativePath;
                }

                if ((bool) config('phase8.generator.test_fail_after_replace', false)) {
                    throw new RuntimeException('Phase8 test failpoint: simulated failure after atomic replacements.');
                }

                foreach ($generatedFileSet->newFiles as $relativePath => $_contents) {
                    $sourcePath = $stagingDirectory.DIRECTORY_SEPARATOR.$relativePath;
                    $targetPath = $rootPath.DIRECTORY_SEPARATOR.$relativePath;

                    $this->fileManager->ensureDirectory(dirname($targetPath));

                    if (is_file($targetPath)) {
                        throw new RuntimeException(sprintf('Refusing to overwrite existing generated file [%s].', $relativePath));
                    }

                    if (! rename($sourcePath, $targetPath)) {
                        throw new RuntimeException(sprintf('Failed to atomically move generated file [%s].', $relativePath));
                    }

                    $movedFiles[] = $targetPath;
                }
            } catch (Throwable $throwable) {
                $this->rollbackMovedFiles($movedFiles);
                $this->restoreBackups($replacedBackups);
                $this->fileManager->removeDirectory($stagingDirectory);

                throw $throwable;
            }

            $this->fileManager->removeDirectory($stagingDirectory);

            return new ModuleGenerationResult(
                module: $module,
                createdFiles: $createdFiles,
                updatedFiles: $updatedFiles,
            );
        });
    }

    private function resolveRootPath(): string
    {
        $rootPath = trim((string) config('phase8.generator.root_path', base_path()));

        if ($rootPath === '') {
            throw new RuntimeException('phase8.generator.root_path cannot be empty.');
        }

        $this->fileManager->ensureDirectory($rootPath);

        return $rootPath;
    }

    private function resolveStagingRoot(): string
    {
        $stagingRoot = trim((string) config('phase8.generator.staging_root', storage_path('framework/module-generator')));

        if ($stagingRoot === '') {
            throw new RuntimeException('phase8.generator.staging_root cannot be empty.');
        }

        $this->fileManager->ensureDirectory($stagingRoot);

        return $stagingRoot;
    }

    private function assertSameFilesystem(string $rootPath, string $stagingRoot): void
    {
        $rootStats = @stat($rootPath);
        $stagingStats = @stat($stagingRoot);

        if (! is_array($rootStats) || ! is_array($stagingStats)) {
            throw new RuntimeException('Unable to stat root/staging paths for same-filesystem validation.');
        }

        if ($rootStats['dev'] !== $stagingStats['dev']) {
            throw new RuntimeException(sprintf(
                'Staging directory [%s] must be on the same filesystem as root path [%s].',
                $stagingRoot,
                $rootPath,
            ));
        }
    }

    /**
     * @param  array<string, ?string>  $replacedBackups
     */
    private function restoreBackups(array $replacedBackups): void
    {
        foreach ($replacedBackups as $targetPath => $backupContents) {
            if ($backupContents === null) {
                @unlink($targetPath);

                continue;
            }

            $this->fileManager->atomicReplace($targetPath, $backupContents);
        }
    }

    /**
     * @param  list<string>  $movedFiles
     */
    private function rollbackMovedFiles(array $movedFiles): void
    {
        foreach ($movedFiles as $movedFile) {
            @unlink($movedFile);
        }
    }

    /**
     * @param  list<string>  $extensions
     * @return array<string, string>
     */
    private function collectByExtension(GeneratedFileSet $generatedFileSet, array $extensions): array
    {
        $normalizedExtensions = array_values(array_unique(array_filter(array_map(
            static fn (string $extension): string => strtolower(trim($extension)),
            $extensions,
        ), static fn (string $extension): bool => $extension !== '')));

        $files = [];

        foreach ($generatedFileSet->newFiles as $path => $contents) {
            $extension = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));

            if (in_array($extension, $normalizedExtensions, true)) {
                $files[$path] = $contents;
            }
        }

        foreach ($generatedFileSet->replacements as $path => $contents) {
            $extension = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));

            if (in_array($extension, $normalizedExtensions, true)) {
                $files[$path] = $contents;
            }
        }

        return $files;
    }
}
