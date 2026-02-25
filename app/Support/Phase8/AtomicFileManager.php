<?php

declare(strict_types=1);

namespace App\Support\Phase8;

use RuntimeException;

final class AtomicFileManager
{
    public function atomicReplace(string $targetFile, string $contents): void
    {
        $directory = dirname($targetFile);

        if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new RuntimeException(sprintf('Failed to create directory [%s].', $directory));
        }

        $tempFile = $directory.DIRECTORY_SEPARATOR.'.phase8_'.bin2hex(random_bytes(8)).'.tmp';

        if (file_put_contents($tempFile, $contents) === false) {
            throw new RuntimeException(sprintf('Failed to write temporary file [%s].', $tempFile));
        }

        if (! rename($tempFile, $targetFile)) {
            @unlink($tempFile);

            throw new RuntimeException(sprintf('Failed to atomically replace file [%s].', $targetFile));
        }
    }

    public function ensureDirectory(string $directory): void
    {
        if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new RuntimeException(sprintf('Failed to create directory [%s].', $directory));
        }
    }

    public function removeDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        $items = scandir($directory);

        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $directory.DIRECTORY_SEPARATOR.$item;

            if (is_dir($path)) {
                $this->removeDirectory($path);

                continue;
            }

            @unlink($path);
        }

        @rmdir($directory);
    }
}
