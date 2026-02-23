<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Sso\DomainCanonicalizer;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ApplyPlatformHsts
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        if (! $request->isSecure()) {
            return $response;
        }

        $headerValue = 'max-age=31536000';

        if ($this->isTrustedPlatformDomain($request->getHost()) && (bool) config('sso.security.enable_hsts_preload', false)) {
            $maxAge = max(31536000, (int) config('sso.security.hsts_max_age', 63072000));
            $headerValue = sprintf('max-age=%d; includeSubDomains; preload', $maxAge);
        }

        $response->headers->set('Strict-Transport-Security', $headerValue);

        return $response;
    }

    private function isTrustedPlatformDomain(string $host): bool
    {
        $trustedDomains = config('sso.security.trusted_platform_domains', []);

        if (! is_array($trustedDomains)) {
            return false;
        }

        try {
            $canonicalHost = DomainCanonicalizer::canonicalize($host);
        } catch (\InvalidArgumentException) {
            return false;
        }

        foreach ($trustedDomains as $trustedDomain) {
            if (! is_string($trustedDomain) || $trustedDomain === '') {
                continue;
            }

            try {
                if (DomainCanonicalizer::canonicalize($trustedDomain) === $canonicalHost) {
                    return true;
                }
            } catch (\InvalidArgumentException) {
                continue;
            }
        }

        return false;
    }
}
