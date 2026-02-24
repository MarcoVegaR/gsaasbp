<?php

declare(strict_types=1);

namespace App\Support\Phase4\Invites;

use App\Models\InviteToken;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

final class InviteService
{
    /**
     * @return array{token: InviteToken, throttled: bool}
     */
    public function issue(string $tenantId, User $actor, string $email): array
    {
        $normalizedEmail = mb_strtolower(trim($email));
        $now = CarbonImmutable::now();

        $softLimit = max(1, (int) config('phase4.invites.soft_limit_per_minute', 5));
        $ttlMinutes = max(1, (int) config('phase4.invites.ttl_minutes', 1440));
        $rateLimitKey = sprintf('phase4:invites:%s:%d', $tenantId, (int) $actor->getAuthIdentifier());

        $throttled = RateLimiter::tooManyAttempts($rateLimitKey, $softLimit);
        RateLimiter::hit($rateLimitKey, 60);

        /** @var InviteToken $token */
        $token = InviteToken::query()->create([
            'jti' => (string) Str::uuid(),
            'tenant_id' => $tenantId,
            'sub' => $normalizedEmail,
            'invited_by' => (int) $actor->getAuthIdentifier(),
            'central_user_id' => null,
            'retry_count' => 0,
            'expires_at' => $now->addMinutes($ttlMinutes),
            'consumed_at' => null,
        ]);

        return [
            'token' => $token,
            'throttled' => $throttled,
        ];
    }
}
