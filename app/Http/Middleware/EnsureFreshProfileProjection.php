<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\TenantUserProfileProjection;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureFreshProfileProjection
{
    public function handle(Request $request, Closure $next): Response
    {
        $tenantId = (string) tenant()?->getTenantKey();
        $userId = $request->user()?->getAuthIdentifier();

        if ($tenantId === '' || $userId === null) {
            return $this->forbidden('PROFILE_PROJECTION_MISSING');
        }

        $projection = TenantUserProfileProjection::query()
            ->where('tenant_id', $tenantId)
            ->where('central_user_id', (int) $userId)
            ->first();

        if (! $projection instanceof TenantUserProfileProjection) {
            return $this->forbidden('PROFILE_PROJECTION_MISSING');
        }

        if ($projection->stale_after !== null && CarbonImmutable::instance($projection->stale_after)->isPast()) {
            return response()->json([
                'code' => 'PROFILE_PROJECTION_STALE',
                'message' => 'Profile projection is stale. Refresh required.',
            ], 409);
        }

        return $next($request);
    }

    private function forbidden(string $code): Response
    {
        return response()->json([
            'code' => $code,
            'message' => 'Profile projection is not available.',
        ], 403);
    }
}
