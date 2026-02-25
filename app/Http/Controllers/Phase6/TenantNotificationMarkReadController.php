<?php

declare(strict_types=1);

namespace App\Http\Controllers\Phase6;

use App\Http\Controllers\Controller;
use App\Models\TenantNotification;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class TenantNotificationMarkReadController extends Controller
{
    public function __invoke(Request $request, string $notificationId): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401, 'Unauthorized.');

        $tenantId = (string) tenant()?->getTenantKey();
        abort_if($tenantId === '', 404);

        /** @var TenantNotification|null $notification */
        $notification = TenantNotification::query()
            ->where('tenant_id', $tenantId)
            ->where('notifiable_id', (int) $user->getAuthIdentifier())
            ->where('id', trim($notificationId))
            ->first();

        abort_if(! $notification instanceof TenantNotification, 404);

        if (! $notification->is_read) {
            $notification->forceFill([
                'is_read' => true,
                'read_at' => CarbonImmutable::now(),
            ])->save();
        }

        return response()->json([
            'id' => (string) $notification->id,
            'is_read' => (bool) $notification->is_read,
            'read_at' => $notification->read_at?->toIso8601String(),
        ]);
    }
}
