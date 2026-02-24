<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Superadmin denylist (domain invariants)
    |--------------------------------------------------------------------------
    |
    | Abilities listed here are never short-circuited by Gate::before and must
    | always be evaluated by their corresponding policy checks.
    |
    */
    'abilities' => [
        'platform.tenants.hard-delete',
    ],
];
