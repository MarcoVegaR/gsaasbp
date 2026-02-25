<?php

declare(strict_types=1);

namespace App\Http\Controllers\Phase5;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Phase5\PlatformContext;
use App\Support\Phase5\PlatformContextStore;
use App\Support\Phase5\PlatformTenantLifecycleService;
use App\Support\Phase7\HardDeleteApprovalService;
use App\Support\Phase7\SecurityAlarmLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AdminHardDeleteTenantController extends Controller
{
    public function __invoke(
        Request $request,
        string $tenantId,
        HardDeleteApprovalService $approvals,
        PlatformContextStore $contextStore,
        PlatformTenantLifecycleService $lifecycle,
        SecurityAlarmLogger $securityAlarm,
    ): JsonResponse {
        $actor = $request->user('platform');
        abort_unless($actor instanceof User, 401, 'Unauthorized.');

        $this->authorize('platform.tenants.hard-delete.execute');

        $validated = $request->validate([
            'approval_id' => ['required', 'string', 'max:255'],
            'reason_code' => ['required', 'string', 'max:120'],
            'ticket' => ['nullable', 'string', 'max:255'],
        ]);

        $approvalConsumed = $approvals->consume(
            approvalId: (string) $validated['approval_id'],
            tenantId: $tenantId,
            executorPlatformUserId: (int) $actor->getAuthIdentifier(),
            reasonCode: (string) $validated['reason_code'],
        );

        if (! $approvalConsumed) {
            $securityAlarm->record('hard_delete_approval_rejected', [
                'tenant_id' => $tenantId,
                'executor_platform_user_id' => (int) $actor->getAuthIdentifier(),
            ]);

            return response()->json([
                'code' => 'INVALID_HARD_DELETE_APPROVAL',
            ], 409);
        }

        $contextStore->run(
            new PlatformContext(
                platformUserId: (int) $actor->getAuthIdentifier(),
                source: 'http:admin',
                ticket: isset($validated['ticket']) ? trim((string) $validated['ticket']) : null,
            ),
            fn (): bool => tap(true, fn () => $lifecycle->hardDeleteTenant($tenantId)),
        );

        return response()->json([
            'status' => 'accepted',
            'tenant_id' => $tenantId,
            'tenant_status' => 'hard_deleted',
        ], 202);
    }
}
