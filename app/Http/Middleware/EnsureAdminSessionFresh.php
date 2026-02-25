<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

final class EnsureAdminSessionFresh
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->isAdminRequest($request)) {
            return $next($request);
        }

        $user = $request->user('platform');

        if ($user === null) {
            return $next($request);
        }

        $timeout = max(60, (int) config('phase7.admin.inactivity_timeout_seconds', 900));
        $lastSeenAt = (int) $request->session()->get('phase7.admin.last_seen_at', 0);

        if ($lastSeenAt > 0 && (now()->getTimestamp() - $lastSeenAt) > $timeout) {
            Auth::guard('platform')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return response()->json([
                'code' => 'ADMIN_SESSION_EXPIRED',
                'message' => 'Platform session expired due to inactivity.',
            ], 401);
        }

        $request->session()->put('phase7.admin.last_seen_at', now()->getTimestamp());

        return $next($request);
    }

    private function isAdminRequest(Request $request): bool
    {
        $path = trim((string) $request->path(), '/');

        return $path === 'admin' || str_starts_with($path, 'admin/');
    }
}
