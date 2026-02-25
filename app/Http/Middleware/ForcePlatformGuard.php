<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

final class ForcePlatformGuard
{
    public function handle(Request $request, Closure $next): Response
    {
        $defaultGuardActor = $request->user();
        $platformActor = $request->user('platform');

        if ($platformActor === null && $defaultGuardActor !== null) {
            abort(403, 'Forbidden.');
        }

        Auth::shouldUse('platform');

        return $next($request);
    }
}
