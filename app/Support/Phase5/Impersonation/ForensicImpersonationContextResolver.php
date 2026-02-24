<?php

declare(strict_types=1);

namespace App\Support\Phase5\Impersonation;

use InvalidArgumentException;

final class ForensicImpersonationContextResolver
{
    /**
     * @param  array<string, mixed>  $trustedClaims
     * @param  array<string, mixed>  $requestPayload
     * @return array<string, string|null>
     */
    public function resolve(array $trustedClaims, array $requestPayload = []): array
    {
        $act = $trustedClaims['act'] ?? null;

        if (! is_array($act)) {
            throw new InvalidArgumentException('Missing impersonation context.');
        }

        $subjectUserId = $trustedClaims['sub'] ?? null;
        $actorUserId = $act['sub'] ?? null;
        $ticket = $act['ticket'] ?? null;

        if (! is_string($subjectUserId) || trim($subjectUserId) === '') {
            throw new InvalidArgumentException('Invalid impersonation subject.');
        }

        if (! is_string($actorUserId) || trim($actorUserId) === '') {
            throw new InvalidArgumentException('Invalid impersonation actor.');
        }

        return [
            'actor_platform_user_id' => $actorUserId,
            'subject_user_id' => $subjectUserId,
            'impersonation_ticket_id' => is_string($ticket) && trim($ticket) !== '' ? $ticket : null,
            'is_impersonating' => 'true',
        ];
    }
}
