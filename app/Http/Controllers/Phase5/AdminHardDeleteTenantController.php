<?php

declare(strict_types=1);

namespace App\Http\Controllers\Phase5;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AdminHardDeleteTenantController extends Controller
{
    public function __invoke(Request $request, string $tenantId): JsonResponse
    {
        $this->authorize('platform.tenants.hard-delete');

        return response()->json([
            'status' => 'accepted',
            'tenant_id' => $tenantId,
        ], 202);
    }
}
