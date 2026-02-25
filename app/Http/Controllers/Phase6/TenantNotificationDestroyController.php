<?php

declare(strict_types=1);

namespace App\Http\Controllers\Phase6;

use App\Http\Controllers\Controller;
use App\Models\TenantNotification;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class TenantNotificationDestroyController extends Controller
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

        $notification->delete();

        return response()->json(status: 204);
    }
}
