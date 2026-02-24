<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class RejectImpersonatedMutations
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->isMutation($request)) {
            return $next($request);
        }

        $context = $request->session()->get('phase5.impersonation');

        if (! is_array($context) || ($context['is_impersonating'] ?? null) !== 'true') {
            return $next($request);
        }

        $routeName = (string) optional($request->route())->getName();
        $allowlist = array_values(array_filter(array_map(
            static fn (string $name): string => trim($name),
            (array) config('phase5.impersonation.mutation_allowlist', []),
        ), static fn (string $name): bool => $name !== ''));

        if (in_array($routeName, $allowlist, true)) {
            return $next($request);
        }

        return response()->json([
            'code' => 'IMPERSONATION_MUTATION_BLOCKED',
        ], 423);
    }

    private function isMutation(Request $request): bool
    {
        return in_array(strtoupper($request->getMethod()), ['POST', 'PUT', 'PATCH', 'DELETE'], true);
    }
}
