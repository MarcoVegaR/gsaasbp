<?php

declare(strict_types=1);

namespace App\Http\Controllers\Phase5;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Phase5\PlatformContext;
use App\Support\Phase5\PlatformContextStore;
use App\Support\Phase5\PlatformTenantLifecycleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

final class AdminTenantStatusController extends Controller
{
    public function __invoke(
        Request $request,
        PlatformContextStore $platformContextStore,
        PlatformTenantLifecycleService $lifecycle,
    ): JsonResponse {
        $actor = $request->user('platform');
        abort_unless($actor instanceof User, 401, 'Unauthorized.');

        $this->authorize('platform.tenants.manage-status');

        $validated = $request->validate([
            'tenant_id' => ['required', 'string', 'max:255'],
            'status' => ['required', 'string', 'max:50'],
            'ticket' => ['nullable', 'string', 'max:255'],
        ]);

        $tenantId = trim((string) $validated['tenant_id']);
        $status = trim(strtolower((string) $validated['status']));

        if ($tenantId === '' || $status === '') {
            throw new InvalidArgumentException('Invalid tenant status payload.');
        }

        $platformContextStore->run(
            new PlatformContext(
                platformUserId: (int) $actor->getAuthIdentifier(),
                source: 'http:admin',
                ticket: isset($validated['ticket']) ? trim((string) $validated['ticket']) : null,
            ),
            fn (): bool => tap(true, fn () => $lifecycle->setTenantStatus($tenantId, $status)),
        );

        return response()->json([
            'status' => 'ok',
            'tenant_id' => $tenantId,
            'tenant_status' => $status,
        ]);
    }
}
