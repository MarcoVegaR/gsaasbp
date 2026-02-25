<?php

declare(strict_types=1);

namespace App\Http\Controllers\Phase7;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Phase7\BillingExplorerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AdminBillingReconcileController extends Controller
{
    public function __invoke(Request $request, BillingExplorerService $billing): JsonResponse
    {
        $actor = $request->user('platform');
        abort_unless($actor instanceof User, 401, 'Unauthorized.');

        $this->authorize('platform.billing.reconcile');

        $validated = $request->validate([
            'tenant_id' => ['required', 'string', 'max:255'],
        ]);

        $billing->dispatchReconcile(
            tenantId: (string) $validated['tenant_id'],
            actorId: (int) $actor->getAuthIdentifier(),
        );

        return response()->json([
            'status' => 'accepted',
            'tenant_id' => (string) $validated['tenant_id'],
        ], 202);
    }
}
