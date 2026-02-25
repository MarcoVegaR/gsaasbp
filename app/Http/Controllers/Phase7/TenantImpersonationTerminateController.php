<?php

declare(strict_types=1);

namespace App\Http\Controllers\Phase7;

use App\Http\Controllers\Controller;
use App\Support\Phase7\ImpersonationSessionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

final class TenantImpersonationTerminateController extends Controller
{
    public function __invoke(Request $request, ImpersonationSessionService $impersonation): JsonResponse
    {
        $context = $request->session()->get('phase5.impersonation');

        if (! is_array($context) || ($context['is_impersonating'] ?? null) !== 'true') {
            return response()->json([
                'code' => 'IMPERSONATION_NOT_ACTIVE',
            ], 404);
        }

        $jti = trim((string) ($context['jti'] ?? ''));

        if ($jti !== '') {
            $impersonation->terminate($jti);
        }

        $request->session()->forget('phase5.impersonation');
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json([
            'status' => 'terminated',
        ]);
    }
}
