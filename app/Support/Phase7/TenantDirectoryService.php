<?php

declare(strict_types=1);

namespace App\Support\Phase7;

use App\Models\Domain;
use App\Models\Tenant;
use App\Models\TenantEntitlement;
use App\Models\TenantSubscription;
use App\Support\SystemContext;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

final class TenantDirectoryService
{
    public function paginated(int $perPage = 25): LengthAwarePaginator
    {
        $limit = max(1, min(100, $perPage));

        /** @var LengthAwarePaginator $paginator */
        $paginator = Tenant::query()
            ->orderBy('id')
            ->paginate($limit, ['id', 'status', 'status_changed_at', 'created_at']);

        /** @var Collection<int, Tenant> $items */
        $items = collect($paginator->items());
        $tenantIds = $items->map(static fn (Tenant $tenant): string => (string) $tenant->getTenantKey())->values()->all();

        $domainsByTenant = $this->domainsByTenant($tenantIds);
        $subscriptionsByTenant = $this->subscriptionsByTenant($tenantIds);
        $entitlementCounts = $this->entitlementCountsByTenant($tenantIds);

        $mapped = $items->map(static function (Tenant $tenant) use ($domainsByTenant, $subscriptionsByTenant, $entitlementCounts): array {
            $tenantId = (string) $tenant->getTenantKey();
            $subscription = $subscriptionsByTenant[$tenantId] ?? null;

            return [
                'tenant_id' => $tenantId,
                'domain' => $domainsByTenant[$tenantId] ?? null,
                'status' => (string) ($tenant->getAttribute('status') ?? 'active'),
                'status_changed_at' => optional($tenant->getAttribute('status_changed_at'))->toIso8601String(),
                'created_at' => optional($tenant->created_at)->toIso8601String(),
                'billing_provider' => $subscription !== null ? (string) $subscription->provider : null,
                'billing_status' => $subscription !== null ? (string) $subscription->status : null,
                'entitlements_granted' => (int) ($entitlementCounts[$tenantId] ?? 0),
            ];
        })->values()->all();

        $paginator->setCollection(collect($mapped));

        return $paginator;
    }

    /**
     * @param  array<int, string>  $tenantIds
     * @return array<string, string>
     */
    private function domainsByTenant(array $tenantIds): array
    {
        if ($tenantIds === []) {
            return [];
        }

        /** @var array<string, string> $result */
        $result = SystemContext::execute(function () use ($tenantIds): array {
            return Domain::query()
                ->whereIn('tenant_id', $tenantIds)
                ->orderBy('domain')
                ->get(['tenant_id', 'domain'])
                ->groupBy('tenant_id')
                ->map(static fn ($rows): string => (string) ($rows->first()?->domain ?? ''))
                ->filter(static fn (string $domain): bool => $domain !== '')
                ->all();
        }, purpose: 'admin.tenants.list');

        return $result;
    }

    /**
     * @param  array<int, string>  $tenantIds
     * @return array<string, TenantSubscription>
     */
    private function subscriptionsByTenant(array $tenantIds): array
    {
        if ($tenantIds === []) {
            return [];
        }

        /** @var array<string, TenantSubscription> $result */
        $result = SystemContext::execute(function () use ($tenantIds): array {
            return TenantSubscription::query()
                ->whereIn('tenant_id', $tenantIds)
                ->where('provider', (string) config('billing.default_provider', 'local'))
                ->get(['tenant_id', 'provider', 'status'])
                ->keyBy('tenant_id')
                ->all();
        }, purpose: 'admin.tenants.list');

        return $result;
    }

    /**
     * @param  array<int, string>  $tenantIds
     * @return array<string, int>
     */
    private function entitlementCountsByTenant(array $tenantIds): array
    {
        if ($tenantIds === []) {
            return [];
        }

        /** @var array<string, int> $result */
        $result = SystemContext::execute(function () use ($tenantIds): array {
            return TenantEntitlement::query()
                ->selectRaw('tenant_id, SUM(CASE WHEN granted THEN 1 ELSE 0 END) as granted_count')
                ->whereIn('tenant_id', $tenantIds)
                ->groupBy('tenant_id')
                ->pluck('granted_count', 'tenant_id')
                ->map(static fn (mixed $value): int => (int) $value)
                ->all();
        }, purpose: 'admin.tenants.list');

        return $result;
    }
}
