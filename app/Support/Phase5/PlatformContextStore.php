<?php

declare(strict_types=1);

namespace App\Support\Phase5;

use RuntimeException;

final class PlatformContextStore
{
    private ?PlatformContext $context = null;

    public function run(PlatformContext $context, callable $callback): mixed
    {
        $previous = $this->context;
        $this->context = $context;

        try {
            return $callback();
        } finally {
            $this->context = $previous;
        }
    }

    public function require(): PlatformContext
    {
        if (! $this->context instanceof PlatformContext) {
            throw new RuntimeException('PLATFORM_CONTEXT_REQUIRED');
        }

        return $this->context;
    }

    public function hasContext(): bool
    {
        return $this->context instanceof PlatformContext;
    }
}
