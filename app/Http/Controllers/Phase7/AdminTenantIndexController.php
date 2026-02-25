<?php

declare(strict_types=1);

namespace App\Http\Controllers\Phase7;

use App\Http\Controllers\Controller;
use App\Support\Phase7\TenantDirectoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AdminTenantIndexController extends Controller
{
    public function __invoke(Request $request, TenantDirectoryService $directory): JsonResponse
    {
        $this->authorize('platform.tenants.view');

        $paginator = $directory->paginated((int) $request->integer('per_page', 25));

        return response()->json([
            'data' => $paginator->items(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }
}
