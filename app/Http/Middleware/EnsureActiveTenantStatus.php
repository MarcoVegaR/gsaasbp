<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Exceptions\TenantStatusBlockedException;
use App\Support\Phase5\TenantStatusService;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureActiveTenantStatus
{
    public function __construct(
        private readonly TenantStatusService $tenantStatus,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $tenantId = (string) tenant()?->getTenantKey();

        if ($tenantId === '') {
            return $next($request);
        }

        try {
            $this->tenantStatus->ensureActive($tenantId);
        } catch (TenantStatusBlockedException $exception) {
            return $this->blockedResponse($exception);
        }

        return $next($request);
    }

    private function blockedResponse(TenantStatusBlockedException $exception): JsonResponse
    {
        return response()->json([
            'code' => 'TENANT_STATUS_BLOCKED',
            'status' => $exception->status(),
        ], 423);
    }
}
