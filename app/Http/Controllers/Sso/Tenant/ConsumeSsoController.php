<?php

declare(strict_types=1);

namespace App\Http\Controllers\Sso\Tenant;

use App\Http\Controllers\Controller;
use App\Support\Sso\RedirectPathGuard;
use App\Support\Sso\SsoAuditLogger;
use App\Support\Sso\SsoCodeStore;
use App\Support\Sso\SsoJwtAssertionService;
use App\Support\Sso\SsoMembershipService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;

class ConsumeSsoController extends Controller
{
    public function __invoke(
        Request $request,
        SsoCodeStore $codeStore,
        SsoJwtAssertionService $jwtAssertionService,
        SsoMembershipService $membershipService,
        SsoAuditLogger $auditLogger,
    ): RedirectResponse {
        $tenantId = (string) tenant()?->getTenantKey();

        abort_if($tenantId === '', 403, 'Forbidden.');

        $mode = 'unknown';
        $userId = null;

        try {
            $state = (string) $request->input('state', '');

            if ($request->filled('code')) {
                $mode = 'backchannel';

                $consumed = $codeStore->consume($tenantId, (string) $request->input('code'));

                if (! is_array($consumed)) {
                    throw new AuthorizationException('Forbidden.');
                }

                $expectedState = (string) ($consumed['state'] ?? '');

                if ($state === '' || ! hash_equals($expectedState, $state)) {
                    throw new AuthorizationException('Forbidden.');
                }

                $userId = (int) ($consumed['user_id'] ?? 0);
                $redirectPath = RedirectPathGuard::normalize((string) ($consumed['redirect_path'] ?? '/'));
            } elseif ($request->filled('assertion')) {
                $mode = 'frontchannel';

                $assertionPayload = $jwtAssertionService->validateAndConsume((string) $request->input('assertion'));
                $assertionTenantId = $assertionPayload['tenant_id'] ?? null;

                if (! is_string($assertionTenantId) || $assertionTenantId !== $tenantId) {
                    throw new AuthorizationException('Forbidden.');
                }

                $expectedState = (string) ($assertionPayload['state'] ?? '');

                if ($state === '' || ! hash_equals($expectedState, $state)) {
                    throw new AuthorizationException('Forbidden.');
                }

                $userId = (int) ($assertionPayload['user_id'] ?? 0);
                $redirectPath = RedirectPathGuard::normalize((string) ($assertionPayload['redirect_path'] ?? '/'));
            } else {
                throw new AuthorizationException('Forbidden.');
            }

            if ($userId <= 0) {
                throw new AuthorizationException('Forbidden.');
            }

            $membership = $membershipService->assertActiveMembership($tenantId, $userId);

            if (! Auth::loginUsingId($userId, false)) {
                throw new AuthorizationException('Forbidden.');
            }

            $request->session()->regenerate();
            $membershipService->touchLastSsoAt($membership);
            $auditLogger->logConsume($request, $tenantId, $userId, $mode, 'ok');

            return redirect()->to($redirectPath);
        } catch (AuthorizationException|InvalidArgumentException) {
            $auditLogger->logConsume($request, $tenantId, $userId, $mode, 'denied');
            abort(403, 'Forbidden.');
        }
    }
}
