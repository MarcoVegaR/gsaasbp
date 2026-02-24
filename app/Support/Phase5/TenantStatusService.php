<?php

declare(strict_types=1);

namespace App\Support\Phase5;

use App\Events\Phase5\TenantStatusChanged;
use App\Exceptions\TenantStatusBlockedException;
use App\Models\Tenant;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;

final class TenantStatusService
{
    public function status(string $tenantId): string
    {
        return (string) $this->store()->remember(
            $this->cacheKey($tenantId),
            now()->addSeconds($this->ttlSeconds()),
            function () use ($tenantId): string {
                /** @var Tenant|null $tenant */
                $tenant = Tenant::query()->find($tenantId);

                if (! $tenant instanceof Tenant) {
                    throw new InvalidArgumentException('Unknown tenant.');
                }

                $status = strtolower(trim((string) ($tenant->getAttribute('status') ?? 'active')));

                return $status !== '' ? $status : 'active';
            },
        );
    }

    public function ensureActive(string $tenantId): void
    {
        $status = $this->status($tenantId);
        $blockedStatuses = array_values(array_filter(array_map(
            static fn (string $value): string => trim(strtolower($value)),
            (array) config('phase5.tenant_status.blocked_statuses', ['suspended', 'hard_deleted']),
        ), static fn (string $value): bool => $value !== ''));

        if (in_array($status, $blockedStatuses, true)) {
            throw new TenantStatusBlockedException($tenantId, $status);
        }
    }

    public function setStatus(string $tenantId, string $status): void
    {
        /** @var Tenant|null $tenant */
        $tenant = Tenant::query()->find($tenantId);

        if (! $tenant instanceof Tenant) {
            throw new InvalidArgumentException('Unknown tenant.');
        }

        $normalizedStatus = trim(strtolower($status));

        if ($normalizedStatus === '') {
            throw new InvalidArgumentException('Invalid tenant status.');
        }

        $tenant->forceFill([
            'status' => $normalizedStatus,
            'status_changed_at' => now(),
        ])->save();

        $this->invalidate($tenantId);

        event(new TenantStatusChanged($tenantId, $normalizedStatus));
    }

    public function invalidate(string $tenantId): void
    {
        $this->store()->forget($this->cacheKey($tenantId));
    }

    private function store(): Repository
    {
        return Cache::store((string) config('phase5.tenant_status.cache_store', 'array'));
    }

    private function ttlSeconds(): int
    {
        return max(1, (int) config('phase5.tenant_status.cache_ttl_seconds', 15));
    }

    private function cacheKey(string $tenantId): string
    {
        return 'phase5:tenant_status:'.trim($tenantId);
    }
}
