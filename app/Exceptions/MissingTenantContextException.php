<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

class MissingTenantContextException extends RuntimeException
{
    public static function forHost(string $host): self
    {
        return new self("Tenant context is required for host [{$host}] but no tenant is initialized.");
    }

    public static function forModel(string $modelClass): self
    {
        return new self("Cannot persist model [{$modelClass}] without an active tenant context or explicit tenant_id.");
    }
}
