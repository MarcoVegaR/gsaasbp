<?php

declare(strict_types=1);

namespace App\Http\Controllers\Sso\Central;

use App\Exceptions\TenantStatusBlockedException;
use App\Http\Controllers\Controller;
use App\Models\Domain;
use App\Support\Sso\DomainCanonicalizer;
use App\Support\Sso\RedirectPathGuard;
use App\Support\Sso\SsoAutoSubmitPage;
use App\Support\Sso\SsoCodeStore;
use App\Support\Sso\SsoInitiationRequestGuard;
use App\Support\Sso\SsoJwtAssertionService;
use App\Support\Sso\SsoMembershipService;
use App\Support\Sso\SsoOneTimeTokenStore;
use App\Support\Phase5\TenantStatusService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

class StartSsoController extends Controller
{
    public function __invoke(
        Request $request,
        SsoInitiationRequestGuard $requestGuard,
        SsoMembershipService $membershipService,
        SsoCodeStore $codeStore,
        SsoOneTimeTokenStore $oneTimeTokenStore,
        SsoJwtAssertionService $jwtAssertionService,
        TenantStatusService $tenantStatus,
        SsoAutoSubmitPage $autoSubmitPage,
    ): Response {
        $requestGuard->assert($request);

        $payload = $request->validate([
            'tenant_domain' => ['required', 'string', 'max:255'],
            'redirect_path' => ['nullable', 'string', 'max:2048'],
            'state' => ['nullable', 'string', 'max:255'],
        ]);

        $tenantDomain = DomainCanonicalizer::canonicalize((string) $payload['tenant_domain']);

        $domain = Domain::query()->where('domain', $tenantDomain)->first();

        abort_if($domain === null, 422, 'Unknown tenant domain.');

        $tenantId = (string) $domain->tenant_id;
        $user = $request->user();

        abort_if($user === null, 401, 'Unauthorized.');

        try {
            $tenantStatus->ensureActive($tenantId);
        } catch (TenantStatusBlockedException $exception) {
            abort(423, 'TENANT_STATUS_BLOCKED:'.$exception->status());
        }

        $membershipService->assertActiveMembership($tenantId, (int) $user->getAuthIdentifier());

        $redirectPath = RedirectPathGuard::normalize((string) ($payload['redirect_path'] ?? '/'));
        $state = trim((string) ($payload['state'] ?? ''));

        if ($state === '') {
            $state = Str::random(40);
        }

        $nonce = Str::random(40);
        $actionUrl = sprintf('%s://%s/sso/consume', $request->getScheme(), $tenantDomain);

        if ((string) config('sso.mode', 'backchannel') === 'backchannel') {
            $code = $codeStore->issue($tenantId, (int) $user->getAuthIdentifier(), $redirectPath, $state, $nonce);

            return $autoSubmitPage->response($actionUrl, [
                'code' => $code,
                'state' => $state,
            ]);
        }

        $ttl = max(1, (int) config('sso.assertion_ttl_seconds', 30));
        $now = now()->getTimestamp();
        $jti = (string) Str::uuid();

        $oneTimeTokenStore->issue($tenantId, $jti, $ttl);

        $assertion = $jwtAssertionService->issue([
            'iss' => (string) config('sso.issuer'),
            'aud' => (string) config('sso.audience'),
            'typ' => (string) config('sso.jwt.typ', 'JWT'),
            'jti' => $jti,
            'tenant_id' => $tenantId,
            'user_id' => (string) $user->getAuthIdentifier(),
            'redirect_path' => $redirectPath,
            'state' => $state,
            'nonce' => $nonce,
            'iat' => $now,
            'nbf' => $now,
            'exp' => $now + $ttl,
        ]);

        return $autoSubmitPage->response($actionUrl, [
            'assertion' => $assertion,
            'state' => $state,
        ]);
    }
}
