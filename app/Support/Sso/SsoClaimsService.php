<?php

declare(strict_types=1);

namespace App\Support\Sso;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Cache;

final class SsoClaimsService
{
    public function __construct(
        private readonly SsoMembershipService $membershipService,
        private readonly SsoClaimsQuotaGuard $quotaGuard,
    ) {}

    /**
     * @return array<string, string|bool>
     */
    public function fetchForCaller(S2sCaller $caller, int|string $userId): array
    {
        $this->quotaGuard->assertWithinLimits($caller);

        $normalizedUserId = $this->normalizeUserId($userId);
        $cacheStore = Cache::store((string) config('sso.claims.cache_store', 'array'));
        $cacheKey = $this->cacheKey($caller->tenantId, $normalizedUserId);
        $cachedPayload = $cacheStore->get($cacheKey);

        if (is_array($cachedPayload)) {
            $this->quotaGuard->trackCacheOutcome($caller, hit: true);

            /** @var array<string, string|bool> $cachedPayload */
            return $cachedPayload;
        }

        $this->membershipService->assertActiveMembership($caller->tenantId, $normalizedUserId);

        $user = User::query()->find($normalizedUserId);

        if (! $user instanceof User) {
            throw (new ModelNotFoundException)->setModel(User::class, [$normalizedUserId]);
        }

        $payload = UserClaimsData::fromUser($user, $caller->tenantId)->payload();

        $cacheStore->put(
            $cacheKey,
            $payload,
            now()->addSeconds(max(1, (int) config('sso.claims.cache_ttl_seconds', 60))),
        );

        $this->quotaGuard->trackCacheOutcome($caller, hit: false);

        return $payload;
    }

    private function cacheKey(string $tenantId, int $userId): string
    {
        return sprintf(
            'sso:claims:%s:%s:%d',
            (string) config('sso.claims.version', 'v1'),
            $tenantId,
            $userId,
        );
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
