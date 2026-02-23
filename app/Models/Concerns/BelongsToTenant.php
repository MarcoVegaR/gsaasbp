<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Exceptions\MissingTenantContextException;
use App\Scopes\BelongsToTenantScope;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new BelongsToTenantScope);

        static::creating(function ($model): void {
            if ($model->getAttribute('tenant_id') !== null) {
                return;
            }

            $tenant = tenant();

            if ($tenant === null) {
                throw MissingTenantContextException::forModel($model::class);
            }

            $model->setAttribute('tenant_id', $tenant->getTenantKey());
        });
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(config('tenancy.tenant_model'), 'tenant_id');
    }
}
