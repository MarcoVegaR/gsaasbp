<?php

declare(strict_types=1);

namespace App\Http\Controllers\Phase5;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

final class AdminDashboardController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $activeGuard = Auth::getDefaultDriver();

        abort_unless($activeGuard === 'platform', 500, 'Platform guard is not active.');

        $this->authorize('platform.admin.access');

        $request->session()->put('phase5.admin.last_seen_at', now()->toIso8601String());

        return response()->json([
            'status' => 'ok',
            'guard' => $activeGuard,
        ]);
    }
}
