<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureRbacStepUp
{
    public function handle(Request $request, Closure $next): Response
    {
        $confirmedAt = (int) $request->session()->get('auth.password_confirmed_at', 0);
        $ttl = max(60, (int) config('phase4.rbac.step_up_ttl_seconds', 900));

        if ($confirmedAt <= 0 || (time() - $confirmedAt) > $ttl) {
            if ($request->expectsJson() || $request->header('X-Inertia') === 'true') {
                return response()->json([
                    'code' => 'STEP_UP_REQUIRED',
                    'message' => 'Recent re-authentication is required.',
                ], 423);
            }

            return redirect()->route('password.confirm');
        }

        return $next($request);
    }
}
