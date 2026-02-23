<?php

declare(strict_types=1);

namespace App\Support\Sso;

use App\Models\User;

final class UserClaimsData
{
    public function __construct(
        private readonly string $version,
        private readonly string $tenantId,
        private readonly string $userId,
        private readonly bool $mfaEnabled,
        private readonly bool $emailVerified,
    ) {}

    public static function fromUser(User $user, string $tenantId): self
    {
        return new self(
            version: (string) config('sso.claims.version', 'v1'),
            tenantId: $tenantId,
            userId: (string) $user->getKey(),
            mfaEnabled: $user->two_factor_confirmed_at !== null,
            emailVerified: $user->email_verified_at !== null,
        );
    }

    /**
     * @return array<string, string|bool>
     */
    public function payload(): array
    {
        return [
            'version' => $this->version,
            'tenant_id' => $this->tenantId,
            'user_id' => $this->userId,
            'mfa_enabled' => $this->mfaEnabled,
            'email_verified' => $this->emailVerified,
        ];
    }
}
