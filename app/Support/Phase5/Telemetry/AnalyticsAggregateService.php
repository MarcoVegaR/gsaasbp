<?php

declare(strict_types=1);

namespace App\Support\Phase5\Telemetry;

use App\Models\ActivityLog;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

final class AnalyticsAggregateService
{
    /**
     * @return array{from: string, to: string, series: list<array<string, mixed>>}
     */
    public function aggregate(CarbonImmutable $from, CarbonImmutable $to, ?string $event = null): array
    {
        $cacheKey = $this->cacheKey($from, $to, $event);
        $ttl = max(1, (int) config('phase5.telemetry.analytics.cache_ttl_seconds', 60));

        return Cache::store((string) config('phase5.telemetry.analytics.cache_store', 'array'))
            ->remember($cacheKey, now()->addSeconds($ttl), fn (): array => $this->compute($from, $to, $event));
    }

    /**
     * @return array{from: string, to: string, series: list<array<string, mixed>>}
     */
    private function compute(CarbonImmutable $from, CarbonImmutable $to, ?string $event): array
    {
        $bucketSeconds = max(60, (int) config('phase5.telemetry.analytics.bucket_seconds', 3600));
        $k = max(1, (int) config('phase5.telemetry.analytics.k_anonymity', 10));
        $cap = max(1, (int) config('phase5.telemetry.analytics.contribution_cap_per_tenant', 10));
        $quantum = max(1, (int) config('phase5.telemetry.analytics.rounding_quantum', 5));

        $query = ActivityLog::query()
            ->where('created_at', '>=', $from)
            ->where('created_at', '<', $to)
            ->orderBy('created_at')
            ->get(['tenant_id', 'event', 'created_at']);

        if (is_string($event) && trim($event) !== '') {
            $eventName = trim($event);
            $query = $query->filter(static fn (ActivityLog $entry): bool => $entry->event === $eventName)->values();
        }

        $bucketTenantCounts = [];

        foreach ($query as $entry) {
            $tenantId = trim((string) $entry->tenant_id);

            if ($tenantId === '' || $entry->created_at === null) {
                continue;
            }

            $bucketStart = (int) (floor($entry->created_at->getTimestamp() / $bucketSeconds) * $bucketSeconds);

            $tenantBucketCount = $bucketTenantCounts[$bucketStart][$tenantId] ?? 0;

            if ($tenantBucketCount >= $cap) {
                continue;
            }

            $bucketTenantCounts[$bucketStart][$tenantId] = $tenantBucketCount + 1;
        }

        ksort($bucketTenantCounts);

        $series = [];

        foreach ($bucketTenantCounts as $bucketStart => $tenantCounts) {
            $tenantCount = count($tenantCounts);
            $rawTotal = array_sum($tenantCounts);

            if ($tenantCount < $k) {
                $series[] = [
                    'bucket_start' => CarbonImmutable::createFromTimestampUTC((int) $bucketStart)->toIso8601String(),
                    'value' => null,
                    'tenant_count' => $tenantCount,
                    'suppressed' => true,
                    'suppression_key' => sha1('phase5-suppression|'.$bucketStart.'|'.(string) $event),
                ];

                continue;
            }

            $rounded = (int) (round($rawTotal / $quantum) * $quantum);

            $series[] = [
                'bucket_start' => CarbonImmutable::createFromTimestampUTC((int) $bucketStart)->toIso8601String(),
                'value' => max(0, $rounded),
                'tenant_count' => $tenantCount,
                'suppressed' => false,
                'suppression_key' => null,
            ];
        }

        return [
            'from' => $from->toIso8601String(),
            'to' => $to->toIso8601String(),
            'series' => $series,
        ];
    }

    private function cacheKey(CarbonImmutable $from, CarbonImmutable $to, ?string $event): string
    {
        return sprintf(
            'phase5:analytics:%s:%s:%s',
            sha1($from->toIso8601String()),
            sha1($to->toIso8601String()),
            sha1((string) $event),
        );
    }
}
