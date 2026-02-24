<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Tenant;
use App\Models\User;
use App\Support\Phase4\Authorization\TenantAuthorizationService;

class TenantPolicy
{
    public function __construct(
        private readonly TenantAuthorizationService $authorization,
    ) {}

    public function manageInvites(User $user, Tenant $tenant): bool
    {
        return $this->authorization->canManageInvites((int) $user->getAuthIdentifier(), (string) $tenant->getTenantKey());
    }

    public function manageRbac(User $user, Tenant $tenant): bool
    {
        return $this->authorization->canManageRbac((int) $user->getAuthIdentifier(), (string) $tenant->getTenantKey());
    }

    public function viewAudit(User $user, Tenant $tenant): bool
    {
        return $this->authorization->canViewAudit((int) $user->getAuthIdentifier(), (string) $tenant->getTenantKey());
    }

    public function manageBilling(User $user, Tenant $tenant): bool
    {
        return $this->authorization->canManageBilling((int) $user->getAuthIdentifier(), (string) $tenant->getTenantKey());
    }
}
