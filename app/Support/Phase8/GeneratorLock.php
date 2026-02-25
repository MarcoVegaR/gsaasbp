<?php

declare(strict_types=1);

namespace App\Support\Phase8;

use RuntimeException;

final class GeneratorLock
{
    /**
     * @template T
     *
     * @param  callable():T  $callback
     * @return T
     */
    public function execute(callable $callback)
    {
        $lockFile = (string) config('phase8.generator.lock_file');
        $timeoutSeconds = max(1, (int) config('phase8.generator.lock_timeout_seconds', 30));

        $lockDirectory = dirname($lockFile);

        if (! is_dir($lockDirectory) && ! mkdir($lockDirectory, 0755, true) && ! is_dir($lockDirectory)) {
            throw new RuntimeException(sprintf('Unable to create lock directory [%s].', $lockDirectory));
        }

        $handle = fopen($lockFile, 'c+');

        if (! is_resource($handle)) {
            throw new RuntimeException(sprintf('Unable to open lock file [%s].', $lockFile));
        }

        $deadline = microtime(true) + $timeoutSeconds;

        while (! flock($handle, LOCK_EX | LOCK_NB)) {
            if (microtime(true) >= $deadline) {
                fclose($handle);

                throw new RuntimeException(sprintf('Timeout waiting for generator lock [%s].', $lockFile));
            }

            usleep(100_000);
        }

        try {
            $testHoldMs = max(0, (int) config('phase8.generator.test_lock_hold_ms', 0));

            if ($testHoldMs > 0) {
                usleep($testHoldMs * 1000);
            }

            return $callback();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
