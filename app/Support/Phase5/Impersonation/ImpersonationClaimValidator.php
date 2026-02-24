<?php

declare(strict_types=1);

namespace App\Support\Phase5\Impersonation;

use InvalidArgumentException;

final class ImpersonationClaimValidator
{
    /**
     * @param  array<string, mixed>  $claims
     */
    public function validate(array $claims, string $expectedTenantAudience): void
    {
        if (! isset($claims['act'])) {
            return;
        }

        $act = $claims['act'];

        if (! is_array($act)) {
            throw new InvalidArgumentException('Invalid impersonation act claim.');
        }

        if (array_key_exists('act', $act)) {
            throw new InvalidArgumentException('Nested act claim is not allowed.');
        }

        $subject = $claims['sub'] ?? null;
        $audience = $claims['aud'] ?? null;
        $jti = $claims['jti'] ?? null;
        $actorSubject = $act['sub'] ?? null;
        $actorIssuer = $act['iss'] ?? null;

        if (! is_string($subject) || trim($subject) === '') {
            throw new InvalidArgumentException('Missing impersonation subject.');
        }

        if (! is_string($audience) || ! hash_equals($expectedTenantAudience, $audience)) {
            throw new InvalidArgumentException('Invalid impersonation audience.');
        }

        if (! is_string($jti) || trim($jti) === '') {
            throw new InvalidArgumentException('Missing impersonation jti.');
        }

        if (! is_string($actorSubject) || trim($actorSubject) === '') {
            throw new InvalidArgumentException('Invalid impersonation actor subject.');
        }

        if (! is_string($actorIssuer) || trim($actorIssuer) === '') {
            throw new InvalidArgumentException('Invalid impersonation actor issuer.');
        }

        $allowedIssuers = array_values(array_filter(array_map(
            static fn (string $issuer): string => trim($issuer),
            (array) config('phase5.impersonation.allowed_actor_issuers', []),
        ), static fn (string $issuer): bool => $issuer !== ''));

        if (! in_array($actorIssuer, $allowedIssuers, true)) {
            throw new InvalidArgumentException('Untrusted impersonation actor issuer.');
        }
    }
}
