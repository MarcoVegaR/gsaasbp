<?php

declare(strict_types=1);

namespace App\Support\Phase4;

use App\Models\TenantUser;
use App\Models\TenantUserProfileProjection;
use App\Models\UserSession;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

final class ProfileProjectionService
{
    /**
     * @param  array<string, mixed>  $profile
     */
    public function upsert(string $tenantId, int $centralUserId, array $profile, ?CarbonInterface $occurredAt = null): TenantUserProfileProjection
    {
        $syncedAt = $occurredAt instanceof CarbonInterface
            ? CarbonImmutable::instance($occurredAt)
            : CarbonImmutable::now();

        $ttl = max(1, (int) config('phase4.profile_projection.ttl_seconds', 3600));

        /** @var TenantUserProfileProjection $projection */
        $projection = TenantUserProfileProjection::query()->updateOrCreate(
            [
                'tenant_id' => $tenantId,
                'central_user_id' => $centralUserId,
            ],
            [
                'display_name' => (string) ($profile['display_name'] ?? 'User #'.$centralUserId),
                'avatar_url' => isset($profile['avatar_url']) ? (string) $profile['avatar_url'] : null,
                'mfa_status' => (bool) ($profile['mfa_status'] ?? false),
                'profile_version' => max(1, (int) ($profile['profile_version'] ?? 1)),
                'last_synced_at' => $syncedAt,
                'stale_after' => $syncedAt->addSeconds($ttl),
            ],
        );

        return $projection;
    }

    public function revokeMembership(string $tenantId, int $centralUserId): void
    {
        DB::transaction(function () use ($tenantId, $centralUserId): void {
            TenantUser::query()
                ->where('tenant_id', $tenantId)
                ->where('user_id', $centralUserId)
                ->update([
                    'is_active' => false,
                    'membership_status' => 'revoked',
                    'membership_revoked_at' => CarbonImmutable::now(),
                ]);

            TenantUserProfileProjection::query()
                ->where('tenant_id', $tenantId)
                ->where('central_user_id', $centralUserId)
                ->delete();

            UserSession::query()
                ->where('user_id', $centralUserId)
                ->delete();
        });
    }
}
