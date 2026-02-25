<?php

declare(strict_types=1);

namespace App\Http\Controllers\Phase7;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Phase7\HardDeleteApprovalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AdminHardDeleteApprovalController extends Controller
{
    public function __invoke(
        Request $request,
        string $tenantId,
        HardDeleteApprovalService $approvals,
    ): JsonResponse {
        $actor = $request->user('platform');
        abort_unless($actor instanceof User, 401, 'Unauthorized.');

        $this->authorize('platform.tenants.hard-delete.approve');

        $validated = $request->validate([
            'requested_by_platform_user_id' => ['required', 'integer', 'min:1'],
            'executor_platform_user_id' => ['required', 'integer', 'min:1'],
            'reason_code' => ['required', 'string', 'max:120'],
        ]);

        $approval = $approvals->issue(
            tenantId: $tenantId,
            requestedByPlatformUserId: (int) $validated['requested_by_platform_user_id'],
            approvedByPlatformUserId: (int) $actor->getAuthIdentifier(),
            executorPlatformUserId: (int) $validated['executor_platform_user_id'],
            reasonCode: (string) $validated['reason_code'],
        );

        return response()->json([
            'status' => 'issued',
            'approval_id' => (string) $approval->getKey(),
            'tenant_id' => (string) $approval->tenant_id,
            'executor_platform_user_id' => (int) $approval->executor_platform_user_id,
            'expires_at' => optional($approval->expires_at)->toIso8601String(),
        ], 201);
    }
}
