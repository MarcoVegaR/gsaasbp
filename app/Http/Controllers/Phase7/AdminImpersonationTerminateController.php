<?php

declare(strict_types=1);

namespace App\Http\Controllers\Phase7;

use App\Http\Controllers\Controller;
use App\Support\Phase7\ImpersonationSessionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AdminImpersonationTerminateController extends Controller
{
    public function __invoke(Request $request, ImpersonationSessionService $impersonation): JsonResponse
    {
        $this->authorize('platform.impersonation.terminate');

        $validated = $request->validate([
            'jti' => ['required', 'string', 'max:255'],
        ]);

        if (! $impersonation->terminate((string) $validated['jti'])) {
            return response()->json([
                'code' => 'IMPERSONATION_NOT_FOUND',
            ], 404);
        }

        return response()->json([
            'status' => 'terminated',
            'jti' => (string) $validated['jti'],
        ]);
    }
}
