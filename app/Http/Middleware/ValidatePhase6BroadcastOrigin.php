<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class ValidatePhase6BroadcastOrigin
{
    public function handle(Request $request, Closure $next): Response
    {
        $origin = trim((string) $request->headers->get('Origin', ''));
        $referer = trim((string) $request->headers->get('Referer', ''));

        $candidate = $origin !== '' ? $origin : $referer;

        if (! $this->isAllowed($candidate)) {
            return $this->forbidden();
        }

        return $next($request);
    }

    private function isAllowed(string $candidate): bool
    {
        $normalizedCandidate = $this->normalizeOrigin($candidate);

        if ($normalizedCandidate === null) {
            return false;
        }

        foreach ($this->allowedOrigins() as $allowedOrigin) {
            if ($this->matchesAllowedOrigin($normalizedCandidate, $allowedOrigin)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function allowedOrigins(): array
    {
        $origins = array_values(array_filter(array_map(
            static fn (string $origin): string => trim($origin),
            (array) config('phase6.allowed_origins', []),
        ), static fn (string $origin): bool => $origin !== ''));

        return $origins;
    }

    private function matchesAllowedOrigin(string $candidate, string $allowedOrigin): bool
    {
        $normalizedAllowed = $this->normalizeOrigin($allowedOrigin);

        if ($normalizedAllowed !== null && hash_equals($normalizedAllowed, $candidate)) {
            return true;
        }

        $allowedParts = parse_url($allowedOrigin);

        if ($allowedParts === false) {
            return false;
        }

        $allowedHost = strtolower((string) ($allowedParts['host'] ?? ''));

        if (! str_starts_with($allowedHost, '*.')) {
            return false;
        }

        $candidateParts = parse_url($candidate);

        if ($candidateParts === false) {
            return false;
        }

        $candidateScheme = strtolower((string) ($candidateParts['scheme'] ?? ''));
        $candidateHost = strtolower((string) ($candidateParts['host'] ?? ''));
        $candidatePort = (int) ($candidateParts['port'] ?? ($candidateScheme === 'https' ? 443 : 80));

        $allowedScheme = strtolower((string) ($allowedParts['scheme'] ?? ''));
        $allowedPort = (int) ($allowedParts['port'] ?? ($allowedScheme === 'https' ? 443 : 80));

        if ($candidateScheme !== $allowedScheme || $candidatePort !== $allowedPort) {
            return false;
        }

        $suffix = ltrim(substr($allowedHost, 1), '.');

        if ($suffix === '') {
            return false;
        }

        return str_ends_with($candidateHost, '.'.$suffix) || $candidateHost === $suffix;
    }

    private function normalizeOrigin(string $origin): ?string
    {
        $parts = parse_url(trim($origin));

        if ($parts === false) {
            return null;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));

        if ($scheme === '' || $host === '') {
            return null;
        }

        $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
        $isDefaultPort = ($scheme === 'https' && $port === 443) || ($scheme === 'http' && $port === 80);

        return sprintf('%s://%s%s', $scheme, $host, $isDefaultPort ? '' : ':'.$port);
    }

    private function forbidden(): JsonResponse
    {
        return response()->json([
            'message' => 'Forbidden.',
        ], 403);
    }
}
