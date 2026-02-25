<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class ApplyAdminFrameGuards
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        if (! $this->isAdminRequest($request)) {
            return $response;
        }

        $existingCsp = trim((string) $response->headers->get('Content-Security-Policy', ''));

        if ($existingCsp === '') {
            $response->headers->set('Content-Security-Policy', "frame-ancestors 'none'");
        } elseif (! str_contains(strtolower($existingCsp), 'frame-ancestors')) {
            $response->headers->set('Content-Security-Policy', $existingCsp."; frame-ancestors 'none'");
        }

        $response->headers->set('X-Frame-Options', 'DENY');

        return $response;
    }

    private function isAdminRequest(Request $request): bool
    {
        $path = trim((string) $request->path(), '/');

        return $path === 'admin' || str_starts_with($path, 'admin/');
    }
}
