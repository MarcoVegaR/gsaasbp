<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Exceptions\MissingTenantContextException;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

abstract class BaseTenantController extends Controller
{
    protected function tenantId(Request $request): string
    {
        $tenantId = tenant()?->getTenantKey();

        if (! is_string($tenantId) || $tenantId === '') {
            throw MissingTenantContextException::forHost((string) $request->getHost());
        }

        return $tenantId;
    }
}
