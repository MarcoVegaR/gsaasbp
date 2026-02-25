<?php

declare(strict_types=1);

namespace App\Support\Phase7;

use App\Jobs\ReconcileTenantBillingJob;
use App\Models\BillingEventProcessed;
use App\Models\BillingIncident;
use App\Support\SystemContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use InvalidArgumentException;

final class BillingExplorerService
{
    /**
     * @param  array<string, mixed>  $filters
     */
    public function paginated(CarbonImmutable $from, CarbonImmutable $to, array $filters = [], int $perPage = 50): LengthAwarePaginator
    {
        $this->assertWindow($from, $to);

        $tenantId = $this->cleanString($filters['tenant_id'] ?? null);

        /** @var LengthAwarePaginator $paginator */
        $paginator = SystemContext::execute(function () use ($from, $to, $filters, $perPage): LengthAwarePaginator {
            $query = BillingEventProcessed::query()
                ->where('processed_at', '>=', $from)
                ->where('processed_at', '<', $to);

            $tenantId = $this->cleanString($filters['tenant_id'] ?? null);

            if ($tenantId !== '') {
                $query->where('tenant_id', $tenantId);
            }

            $eventId = $this->cleanString($filters['event_id'] ?? null);

            if ($eventId !== '') {
                $query->where('event_id', $eventId);
            }

            $paginator = $query
                ->orderByDesc('processed_at')
                ->paginate(max(1, min(200, $perPage)));

            $eventIds = collect($paginator->items())
                ->map(static fn (BillingEventProcessed $event): string => (string) $event->event_id)
                ->values()
                ->all();

            $divergentEventIds = BillingIncident::query()
                ->whereIn('event_id', $eventIds)
                ->pluck('event_id')
                ->map(static fn (mixed $eventId): string => (string) $eventId)
                ->all();

            $mapped = collect($paginator->items())
                ->map(static fn (BillingEventProcessed $event): array => [
                    'event_id' => (string) $event->event_id,
                    'tenant_id' => (string) $event->tenant_id,
                    'provider' => (string) $event->provider,
                    'outcome_hash' => (string) $event->outcome_hash,
                    'provider_object_version' => (int) $event->provider_object_version,
                    'processed_at' => optional($event->processed_at)->toIso8601String(),
                    'divergence' => in_array((string) $event->event_id, $divergentEventIds, true),
                ])
                ->values();

            $paginator->setCollection($mapped);

            return $paginator;
        }, purpose: 'admin.billing.events.read', targetTenantId: $tenantId !== '' ? $tenantId : null);

        return $paginator;
    }

    public function dispatchReconcile(string $tenantId, int $actorId): void
    {
        $cleanTenantId = trim($tenantId);

        if ($cleanTenantId === '') {
            throw new InvalidArgumentException('Invalid tenant id.');
        }

        SystemContext::execute(
            fn (): bool => tap(true, fn () => ReconcileTenantBillingJob::dispatch(
                provider: (string) config('billing.default_provider', 'local'),
                tenantId: $cleanTenantId,
                actorId: $actorId,
            )),
            purpose: 'admin.billing.reconcile.dispatch',
            targetTenantId: $cleanTenantId,
        );
    }

    private function assertWindow(CarbonImmutable $from, CarbonImmutable $to): void
    {
        if ($from->greaterThanOrEqualTo($to)) {
            throw new InvalidArgumentException('Invalid billing window.');
        }

        if ($from->diffInDays($to) > max(1, (int) config('phase7.forensics.max_window_days', 30))) {
            throw new InvalidArgumentException('Billing window exceeds allowed range.');
        }
    }

    private function cleanString(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
    }
}
