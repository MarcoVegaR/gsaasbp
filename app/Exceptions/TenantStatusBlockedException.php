<?php

declare(strict_types=1);

namespace App\Exceptions;

use Illuminate\Auth\Access\AuthorizationException;

final class TenantStatusBlockedException extends AuthorizationException
{
    public function __construct(
        private readonly string $tenantId,
        private readonly string $tenantStatus,
    ) {
        parent::__construct('TENANT_STATUS_BLOCKED');
    }

    public function tenantId(): string
    {
        return $this->tenantId;
    }

    public function status(): string
    {
        return $this->tenantStatus;
    }
}
