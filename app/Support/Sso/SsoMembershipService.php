<?php

declare(strict_types=1);

namespace App\Support\Sso;

use App\Models\TenantUser;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;

final class SsoMembershipService
{
    public function assertActiveMembership(string $tenantId, int|string $userId): TenantUser
    {
        $normalizedUserId = $this->normalizeUserId($userId);

        $membership = TenantUser::query()
            ->where('tenant_id', $tenantId)
            ->where('user_id', $normalizedUserId)
            ->first();

        if (! $membership instanceof TenantUser) {
            throw new AuthorizationException('Forbidden.');
        }

        if (! $membership->is_active || $membership->is_banned) {
            throw new AuthorizationException('Forbidden.');
        }

        return $membership;
    }

    public function touchLastSsoAt(TenantUser $membership): void
    {
        $membership->forceFill([
            'last_sso_at' => CarbonImmutable::now(),
        ])->save();
    }

    private function normalizeUserId(int|string $userId): int
    {
        if (is_int($userId)) {
            return $userId;
        }

        $normalized = trim($userId);

        if ($normalized === '' || ! ctype_digit($normalized)) {
            throw new AuthorizationException('Forbidden.');
        }

        return (int) $normalized;
    }
}
