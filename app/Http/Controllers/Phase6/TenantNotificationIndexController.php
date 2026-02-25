<?php

declare(strict_types=1);

namespace App\Http\Controllers\Phase6;

use App\Http\Controllers\Controller;
use App\Models\TenantNotification;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class TenantNotificationIndexController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401, 'Unauthorized.');

        $tenantId = (string) tenant()?->getTenantKey();
        abort_if($tenantId === '', 404);

        $configuredLimit = max(1, (int) config('phase6.notifications.list_limit', 50));
        $limit = min($configuredLimit, max(1, (int) $request->integer('limit', $configuredLimit)));

        $notifications = TenantNotification::query()
            ->where('tenant_id', $tenantId)
            ->where('notifiable_id', (int) $user->getAuthIdentifier())
            ->orderByDesc('sequence')
            ->limit($limit)
            ->get();

        return response()->json([
            'data' => $notifications->map(static fn (TenantNotification $notification): array => [
                'id' => (string) $notification->id,
                'event_type' => (string) $notification->event_type,
                'version' => (int) $notification->version,
                'sequence' => (int) $notification->sequence,
                'is_read' => (bool) $notification->is_read,
                'read_at' => $notification->read_at?->toIso8601String(),
                'occurred_at' => $notification->occurred_at?->toIso8601String(),
            ])->values()->all(),
            'polling_interval_seconds' => max(5, (int) config('phase6.realtime.polling_interval_seconds', 20)),
        ]);
    }
}
