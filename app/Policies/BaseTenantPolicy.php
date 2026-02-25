<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;

abstract class BaseTenantPolicy
{
    public function before(User $user, string $ability): ?bool
    {
        if (! str_starts_with($ability, 'tenant.')) {
            return null;
        }

        if (in_array($ability, config('superadmin_denylist.abilities', []), true)) {
            return null;
        }

        $superadminEmails = array_values(array_filter(array_map(
            static fn (mixed $email): string => trim(strtolower((string) $email)),
            (array) config('phase5.superadmin.emails', []),
        ), static fn (string $email): bool => $email !== ''));

        return in_array(strtolower((string) $user->email), $superadminEmails, true) ? true : null;
    }
}
