<?php

declare(strict_types=1);

namespace App\Scopes;

use App\Exceptions\MissingTenantContextException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class BelongsToTenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $tenant = tenant();

        if ($tenant !== null) {
            $builder->where($model->qualifyColumn('tenant_id'), $tenant->getTenantKey());

            return;
        }

        if ($this->isCentralRequest()) {
            return;
        }

        throw MissingTenantContextException::forHost($this->resolveHost());
    }

    private function isCentralRequest(): bool
    {
        if (! app()->bound('request')) {
            return true;
        }

        return in_array($this->resolveHost(), config('tenancy.central_domains', []), true);
    }

    private function resolveHost(): string
    {
        if (! app()->bound('request')) {
            return 'unknown';
        }

        return (string) request()->getHost();
    }
}
