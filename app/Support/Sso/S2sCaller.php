<?php

declare(strict_types=1);

namespace App\Support\Sso;

final class S2sCaller
{
    public function __construct(
        public readonly string $token,
        public readonly string $tenantId,
        public readonly string $caller,
    ) {}
}
