<?php

declare(strict_types=1);

namespace App\Events\Phase5;

final class TenantStatusChanged
{
    public function __construct(
        public readonly string $tenantId,
        public readonly string $status,
    ) {}
}
