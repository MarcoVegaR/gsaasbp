<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureAdminMutationOrigin
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->isMutation($request) || ! $this->isAdminRequest($request)) {
            return $next($request);
        }

        if (! (bool) config('phase7.admin.mutation_origin_required', true)) {
            return $next($request);
        }

        $origin = trim((string) $request->headers->get('Origin', ''));

        if ($origin === '') {
            return $this->forbidden();
        }

        $originHost = parse_url($origin, PHP_URL_HOST);
        $originScheme = parse_url($origin, PHP_URL_SCHEME);

        if (! is_string($originHost) || ! is_string($originScheme)) {
            return $this->forbidden();
        }

        if (! hash_equals(strtolower($request->getHost()), strtolower($originHost))) {
            return $this->forbidden();
        }

        if (! hash_equals(strtolower($request->getScheme()), strtolower($originScheme))) {
            return $this->forbidden();
        }

        return $next($request);
    }

    private function isMutation(Request $request): bool
    {
        return in_array(strtoupper($request->getMethod()), ['POST', 'PUT', 'PATCH', 'DELETE'], true);
    }

    private function isAdminRequest(Request $request): bool
    {
        $path = trim((string) $request->path(), '/');

        return $path === 'admin' || str_starts_with($path, 'admin/');
    }

    private function forbidden(): Response
    {
        return response()->json([
            'code' => 'INVALID_ORIGIN',
            'message' => 'Cross-origin admin mutation denied.',
        ], 403);
    }
}
