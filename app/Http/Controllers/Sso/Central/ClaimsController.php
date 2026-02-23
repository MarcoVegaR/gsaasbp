<?php

declare(strict_types=1);

namespace App\Http\Controllers\Sso\Central;

use App\Http\Controllers\Controller;
use App\Support\Sso\S2sCaller;
use App\Support\Sso\SsoClaimsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ClaimsController extends Controller
{
    public function __invoke(Request $request, string $userId, SsoClaimsService $claimsService): JsonResponse
    {
        $caller = $request->attributes->get('sso_s2s_caller');

        abort_unless($caller instanceof S2sCaller, 401, 'Unauthorized.');

        $claims = $claimsService->fetchForCaller($caller, $userId);

        return response()->json($claims);
    }
}
