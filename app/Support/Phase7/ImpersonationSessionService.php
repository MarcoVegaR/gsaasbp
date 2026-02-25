<?php

declare(strict_types=1);

namespace App\Support\Phase7;

use App\Models\PlatformImpersonationSession;
use App\Support\Phase5\Impersonation\ForensicImpersonationContextResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class ImpersonationSessionService
{
    public function __construct(
        private readonly ForensicImpersonationContextResolver $forensicResolver,
        private readonly SecurityAlarmLogger $securityAlarm,
    ) {}

    public function issue(
        int $platformUserId,
        string $targetTenantId,
        int $targetUserId,
        string $reasonCode,
        Request $request,
    ): PlatformImpersonationSession {
        $cleanTenantId = trim($targetTenantId);
        $cleanReasonCode = trim($reasonCode);

        if ($platformUserId <= 0 || $targetUserId <= 0 || $cleanTenantId === '' || $cleanReasonCode === '') {
            throw new InvalidArgumentException('Invalid impersonation payload.');
        }

        $this->revokeActiveSessionsForActor($platformUserId);

        /** @var PlatformImpersonationSession $session */
        $session = PlatformImpersonationSession::query()->create([
            'jti' => (string) Str::uuid(),
            'platform_user_id' => $platformUserId,
            'target_tenant_id' => $cleanTenantId,
            'target_user_id' => $targetUserId,
            'reason_code' => $cleanReasonCode,
            'fingerprint' => $this->fingerprint($request),
            'issued_at' => now(),
            'expires_at' => now()->addSeconds(max(60, (int) config('phase7.impersonation.ttl_seconds', 180))),
            'consumed_at' => null,
            'revoked_at' => null,
        ]);

        return $session;
    }

    /**
     * @param  array<string, mixed>  $claims
     * @return array<string, string|null>
     */
    public function consumeFromClaims(array $claims, Request $request): array
    {
        if (! array_key_exists('act', $claims)) {
            return [];
        }

        $jti = trim((string) ($claims['jti'] ?? ''));
        $tenantId = trim((string) ($claims['tenant_id'] ?? ''));
        $targetUserId = (int) ($claims['user_id'] ?? 0);

        if ($jti === '' || $tenantId === '' || $targetUserId <= 0) {
            throw new InvalidArgumentException('Invalid impersonation claims.');
        }

        /** @var PlatformImpersonationSession|null $session */
        $session = PlatformImpersonationSession::query()->find($jti);

        if (! $session instanceof PlatformImpersonationSession
            || $session->revoked_at !== null
            || $session->expires_at === null
            || $session->expires_at->isPast()) {
            throw new InvalidArgumentException('Impersonation session is invalid or expired.');
        }

        if (
            ! hash_equals((string) $session->target_tenant_id, $tenantId)
            || (int) $session->target_user_id !== $targetUserId
        ) {
            $session->forceFill([
                'revoked_at' => now(),
            ])->save();

            $this->securityAlarm->record('impersonation_claim_target_mismatch', [
                'target_tenant_id' => (string) $session->target_tenant_id,
                'target_user_id' => (int) $session->target_user_id,
            ]);

            throw new InvalidArgumentException('Impersonation claim mismatch.');
        }

        if (! hash_equals((string) $session->fingerprint, $this->fingerprint($request))) {
            $session->forceFill([
                'revoked_at' => now(),
            ])->save();

            $this->securityAlarm->record('impersonation_claim_fingerprint_mismatch', [
                'target_tenant_id' => (string) $session->target_tenant_id,
                'target_user_id' => (int) $session->target_user_id,
            ]);

            throw new InvalidArgumentException('Impersonation fingerprint mismatch.');
        }

        if ($session->consumed_at === null) {
            $session->forceFill([
                'consumed_at' => now(),
            ])->save();
        }

        $forensic = $this->forensicResolver->resolve($claims, (array) $request->all());

        return [
            ...$forensic,
            'jti' => $jti,
        ];
    }

    public function terminate(string $jti): bool
    {
        $cleanJti = trim($jti);

        if ($cleanJti === '') {
            return false;
        }

        $affected = PlatformImpersonationSession::query()
            ->whereKey($cleanJti)
            ->whereNull('revoked_at')
            ->update([
                'revoked_at' => now(),
                'updated_at' => now(),
            ]);

        return $affected > 0;
    }

    private function revokeActiveSessionsForActor(int $platformUserId): void
    {
        PlatformImpersonationSession::query()
            ->where('platform_user_id', $platformUserId)
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->update([
                'revoked_at' => now(),
                'updated_at' => now(),
            ]);
    }

    private function fingerprint(Request $request): string
    {
        return hash('sha256', implode('|', [
            (string) $request->userAgent(),
            (string) $request->header('Accept-Language', ''),
        ]));
    }
}
