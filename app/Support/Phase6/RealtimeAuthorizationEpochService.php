<?php

declare(strict_types=1);

namespace App\Support\Phase6;

use App\Models\TenantUserRealtimeEpoch;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class RealtimeAuthorizationEpochService
{
    public function currentEpoch(string $tenantId, int $userId): int
    {
        $record = TenantUserRealtimeEpoch::query()->firstOrCreate(
            [
                'tenant_id' => $tenantId,
                'user_id' => $userId,
            ],
            [
                'authz_epoch' => 1,
                'last_bumped_at' => CarbonImmutable::now(),
            ],
        );

        return max(1, (int) $record->authz_epoch);
    }

    public function bumpEpoch(string $tenantId, int $userId): int
    {
        return DB::transaction(function () use ($tenantId, $userId): int {
            /** @var TenantUserRealtimeEpoch|null $record */
            $record = TenantUserRealtimeEpoch::query()
                ->where('tenant_id', $tenantId)
                ->where('user_id', $userId)
                ->lockForUpdate()
                ->first();

            if (! $record instanceof TenantUserRealtimeEpoch) {
                $record = TenantUserRealtimeEpoch::query()->create([
                    'tenant_id' => $tenantId,
                    'user_id' => $userId,
                    'authz_epoch' => 2,
                    'last_bumped_at' => CarbonImmutable::now(),
                ]);

                return max(1, (int) $record->authz_epoch);
            }

            $record->forceFill([
                'authz_epoch' => max(1, (int) $record->authz_epoch) + 1,
                'last_bumped_at' => CarbonImmutable::now(),
            ])->save();

            return max(1, (int) $record->authz_epoch);
        });
    }
}
