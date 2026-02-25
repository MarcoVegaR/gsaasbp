<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\PlatformImpersonationSession;
use App\Support\Phase7\SecurityAlarmLogger;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnforceImpersonationSession
{
    public function __construct(
        private readonly SecurityAlarmLogger $securityAlarm,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $context = $request->session()->get('phase5.impersonation');

        if (! is_array($context) || ($context['is_impersonating'] ?? null) !== 'true') {
            return $next($request);
        }

        $jti = trim((string) ($context['jti'] ?? ''));

        if ($jti === '') {
            $request->session()->forget('phase5.impersonation');

            return response()->json([
                'code' => 'IMPERSONATION_INVALID',
            ], 403);
        }

        /** @var PlatformImpersonationSession|null $session */
        $session = PlatformImpersonationSession::query()->find($jti);

        if (! $session instanceof PlatformImpersonationSession
            || $session->revoked_at !== null
            || $session->expires_at === null
            || $session->expires_at->isPast()) {
            $request->session()->forget('phase5.impersonation');

            return response()->json([
                'code' => 'IMPERSONATION_EXPIRED',
            ], 403);
        }

        $tenantId = (string) tenant()?->getTenantKey();
        $userId = (int) ($request->user()?->getAuthIdentifier() ?? 0);

        if ($tenantId === ''
            || ! hash_equals((string) $session->target_tenant_id, $tenantId)
            || (int) $session->target_user_id !== $userId) {
            $session->forceFill([
                'revoked_at' => now(),
            ])->save();

            $request->session()->forget('phase5.impersonation');

            $this->securityAlarm->record('impersonation_target_mismatch', [
                'tenant_id' => $tenantId !== '' ? $tenantId : null,
                'target_tenant_id' => (string) $session->target_tenant_id,
                'target_user_id' => (int) $session->target_user_id,
            ]);

            return response()->json([
                'code' => 'IMPERSONATION_TARGET_MISMATCH',
            ], 403);
        }

        $fingerprint = $this->fingerprint($request);

        if (! hash_equals((string) $session->fingerprint, $fingerprint)) {
            $session->forceFill([
                'revoked_at' => now(),
            ])->save();

            $request->session()->forget('phase5.impersonation');

            $this->securityAlarm->record('impersonation_fingerprint_mismatch', [
                'tenant_id' => $tenantId,
                'target_user_id' => $userId,
            ]);

            return response()->json([
                'code' => 'IMPERSONATION_REVOKED',
            ], 403);
        }

        return $next($request);
    }

    private function fingerprint(Request $request): string
    {
        return hash('sha256', implode('|', [
            (string) $request->userAgent(),
            (string) $request->header('Accept-Language', ''),
        ]));
    }
}
