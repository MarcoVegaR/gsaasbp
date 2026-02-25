<?php

declare(strict_types=1);

namespace App\Http\Requests\Tenant;

use App\Exceptions\MissingTenantContextException;
use Illuminate\Foundation\Http\FormRequest;

abstract class BaseTenantRequest extends FormRequest
{
    protected function tenantId(): string
    {
        $tenantId = tenant()?->getTenantKey();

        if (! is_string($tenantId) || $tenantId === '') {
            throw MissingTenantContextException::forHost((string) $this->getHost());
        }

        return $tenantId;
    }
}
