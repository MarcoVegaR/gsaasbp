<?php

declare(strict_types=1);

namespace App\Exceptions;

use LogicException;

class TenantContextMismatchException extends LogicException
{
    public static function forTenant(string $expectedTenantId, ?string $receivedTenantId): self
    {
        return new self("Tenant context mismatch. Expected tenant [{$expectedTenantId}] and received [{$receivedTenantId}].");
    }
}
