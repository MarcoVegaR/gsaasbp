<?php

declare(strict_types=1);

namespace App\Support\Phase5;

final class PlatformContext
{
    public function __construct(
        public readonly int $platformUserId,
        public readonly string $source,
        public readonly ?string $ticket = null,
    ) {}
}
