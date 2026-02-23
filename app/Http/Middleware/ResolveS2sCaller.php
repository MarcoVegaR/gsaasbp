<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Sso\S2sCallerResolver;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveS2sCaller
{
    public function __construct(
        private readonly S2sCallerResolver $resolver,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $caller = $this->resolver->resolve($request);

        if ($caller === null) {
            abort(401, 'Unauthorized.');
        }

        $request->attributes->set('sso_s2s_caller', $caller);

        return $next($request);
    }
}
