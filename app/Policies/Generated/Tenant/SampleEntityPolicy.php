<?php

declare(strict_types=1);

namespace App\Policies\Generated\Tenant;

use App\Models\Generated\Tenant\SampleEntity;
use App\Models\User;
use App\Policies\BaseTenantPolicy;

final class SampleEntityPolicy extends BaseTenantPolicy
{
    public function viewAny(User $user): bool
    {
        return false;
    }

    public function view(User $user, SampleEntity $resource): bool
    {
        return false;
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, SampleEntity $resource): bool
    {
        return false;
    }

    public function delete(User $user, SampleEntity $resource): bool
    {
        return false;
    }
}
