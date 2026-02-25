<?php

declare(strict_types=1);

namespace App\Http\Controllers\Phase7;

use App\Http\Controllers\Controller;
use App\Models\Domain;
use App\Models\User;
use App\Support\Phase7\ImpersonationSessionService;
use App\Support\Sso\DomainCanonicalizer;
use App\Support\Sso\SsoJwtAssertionService;
use App\Support\Sso\SsoOneTimeTokenStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class AdminImpersonationIssueController extends Controller
{
    public function __invoke(
        Request $request,
        ImpersonationSessionService $impersonation,
        SsoOneTimeTokenStore $tokenStore,
        SsoJwtAssertionService $jwt,
    ): JsonResponse {
        $actor = $request->user('platform');
        abort_unless($actor instanceof User, 401, 'Unauthorized.');

        $this->authorize('platform.impersonation.issue');

        $validated = $request->validate([
            'target_tenant_id' => ['required', 'string', 'max:255'],
            'target_user_id' => ['required', 'integer', 'min:1'],
            'tenant_domain' => ['required', 'string', 'max:255'],
            'reason_code' => ['required', 'string', 'max:120'],
            'redirect_path' => ['nullable', 'string', 'max:2048'],
        ]);

        $tenantId = trim((string) $validated['target_tenant_id']);
        $tenantDomain = DomainCanonicalizer::canonicalize((string) $validated['tenant_domain']);

        $domain = Domain::query()
            ->where('domain', $tenantDomain)
            ->where('tenant_id', $tenantId)
            ->first();

        abort_if($domain === null, 422, 'Unknown tenant domain.');

        $session = $impersonation->issue(
            platformUserId: (int) $actor->getAuthIdentifier(),
            targetTenantId: $tenantId,
            targetUserId: (int) $validated['target_user_id'],
            reasonCode: (string) $validated['reason_code'],
            request: $request,
        );

        $ttl = max(60, (int) config('phase7.impersonation.ttl_seconds', 180));
        $state = Str::random(40);
        $nonce = Str::random(40);
        $now = now()->getTimestamp();

        $tokenStore->issue($tenantId, (string) $session->getKey(), $ttl);

        $assertion = $jwt->issue([
            'iss' => (string) config('sso.issuer'),
            'aud' => (string) config('sso.audience'),
            'typ' => (string) config('sso.jwt.typ', 'JWT'),
            'jti' => (string) $session->getKey(),
            'tenant_id' => $tenantId,
            'user_id' => (string) $validated['target_user_id'],
            'redirect_path' => trim((string) ($validated['redirect_path'] ?? '/tenant/dashboard')),
            'state' => $state,
            'nonce' => $nonce,
            'iat' => $now,
            'nbf' => $now,
            'exp' => $now + $ttl,
            'act' => [
                'sub' => (string) $actor->getAuthIdentifier(),
                'iss' => (string) config('sso.issuer'),
                'ticket' => (string) $validated['reason_code'],
            ],
        ]);

        return response()->json([
            'status' => 'issued',
            'jti' => (string) $session->getKey(),
            'assertion' => $assertion,
            'state' => $state,
            'consume_url' => sprintf('%s://%s/sso/consume', $request->getScheme(), $tenantDomain),
            'expires_at' => optional($session->expires_at)->toIso8601String(),
        ], 201);
    }
}
