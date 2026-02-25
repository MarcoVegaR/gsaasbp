<?php

declare(strict_types=1);

namespace App\Support\Phase6;

use App\Models\TenantUser;

final class RealtimeMembershipService
{
    public function isActive(string $tenantId, int $userId): bool
    {
        /** @var TenantUser|null $membership */
        $membership = TenantUser::query()
            ->where('tenant_id', $tenantId)
            ->where('user_id', $userId)
            ->first();

        if (! $membership instanceof TenantUser) {
            return false;
        }

        if (! $membership->is_active || $membership->is_banned) {
            return false;
        }

        $status = strtolower(trim((string) $membership->membership_status));

        if ($status === '') {
            $status = 'active';
        }

        return $status === 'active';
    }
}
