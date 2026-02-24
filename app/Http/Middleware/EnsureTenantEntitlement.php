<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Phase4\Entitlements\EntitlementService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureTenantEntitlement
{
    public function __construct(
        private readonly EntitlementService $entitlements,
    ) {}

    public function handle(Request $request, Closure $next, string $feature): Response
    {
        $tenantId = (string) tenant()?->getTenantKey();

        if ($tenantId === '' || ! $this->entitlements->isGranted($tenantId, $feature)) {
            return response()->json([
                'code' => 'BILLING_REQUIRED',
                'message' => 'This feature requires an active billing entitlement.',
            ], 403);
        }

        return $next($request);
    }
}
