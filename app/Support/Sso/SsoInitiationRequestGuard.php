<?php

declare(strict_types=1);

namespace App\Support\Sso;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;

final class SsoInitiationRequestGuard
{
    public function assert(Request $request): void
    {
        $origin = trim((string) $request->headers->get('Origin', ''));
        $referer = trim((string) $request->headers->get('Referer', ''));

        if ($origin !== '' && ! $this->isSameOrigin($origin, $request)) {
            $this->fail();
        }

        if ($referer !== '' && ! $this->isSameOrigin($referer, $request)) {
            $this->fail();
        }

        if ($origin === '' && $referer === '') {
            $fetchSite = strtolower(trim((string) $request->headers->get('Sec-Fetch-Site', '')));

            if (! in_array($fetchSite, ['same-origin', 'same-site'], true)) {
                $this->fail();
            }
        }

        $fetchMode = strtolower(trim((string) $request->headers->get('Sec-Fetch-Mode', '')));

        if ($fetchMode !== '' && $fetchMode !== 'navigate') {
            $this->fail();
        }

        $fetchDest = strtolower(trim((string) $request->headers->get('Sec-Fetch-Dest', '')));

        if ($fetchDest !== '' && $fetchDest !== 'document') {
            $this->fail();
        }
    }

    private function isSameOrigin(string $candidate, Request $request): bool
    {
        $parts = parse_url($candidate);

        if ($parts === false) {
            return false;
        }

        $candidateHost = strtolower((string) ($parts['host'] ?? ''));
        $candidateScheme = strtolower((string) ($parts['scheme'] ?? ''));

        if ($candidateHost === '' || $candidateScheme === '') {
            return false;
        }

        $requestHost = strtolower($request->getHost());
        $requestScheme = strtolower($request->getScheme());

        if ($candidateHost !== $requestHost || $candidateScheme !== $requestScheme) {
            return false;
        }

        $candidatePort = (int) ($parts['port'] ?? ($candidateScheme === 'https' ? 443 : 80));
        $requestPort = (int) ($request->getPort() ?? ($requestScheme === 'https' ? 443 : 80));

        return $candidatePort === $requestPort;
    }

    private function fail(): never
    {
        throw new AuthorizationException('Forbidden.');
    }
}
