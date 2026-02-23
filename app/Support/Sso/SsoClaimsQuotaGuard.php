<?php

declare(strict_types=1);

namespace App\Support\Sso;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

final class SsoClaimsQuotaGuard
{
    public function assertWithinLimits(S2sCaller $caller): void
    {
        $store = Cache::store((string) config('sso.claims.quota_store', 'array'));
        $now = CarbonImmutable::now();

        $minuteLimit = max(1, (int) config('sso.claims.rate_limit_per_minute', 60));
        $minuteKey = sprintf('sso:claims:minute:%s:%s:%s', $caller->tenantId, $caller->caller, $now->format('YmdHi'));
        $minuteCount = $this->increment($store, $minuteKey, $now->addMinutes(2));

        if ($minuteCount > $minuteLimit) {
            throw new TooManyRequestsHttpException(60, 'Claims rate limit exceeded.');
        }

        $dailyLimit = max(1, (int) config('sso.claims.daily_quota', 1000));
        $dailyKey = sprintf('sso:claims:daily:%s:%s:%s', $caller->tenantId, $caller->caller, $now->format('Ymd'));
        $dailyCount = $this->increment($store, $dailyKey, $now->endOfDay()->addSecond());

        if ($dailyCount > $dailyLimit) {
            throw new TooManyRequestsHttpException(3600, 'Claims daily quota exceeded.');
        }

        $weeklyLimit = max(1, (int) config('sso.claims.weekly_quota', 5000));
        $weeklyKey = sprintf('sso:claims:weekly:%s:%s:%s', $caller->tenantId, $caller->caller, $now->isoFormat('GGGG-[W]WW'));
        $weeklyCount = $this->increment($store, $weeklyKey, $now->endOfWeek()->endOfDay()->addSecond());

        if ($weeklyCount > $weeklyLimit) {
            throw new TooManyRequestsHttpException(7200, 'Claims weekly quota exceeded.');
        }
    }

    public function trackCacheOutcome(S2sCaller $caller, bool $hit): void
    {
        $store = Cache::store((string) config('sso.claims.quota_store', 'array'));
        $now = CarbonImmutable::now();
        $bucket = $now->format('YmdHi');
        $suffix = $hit ? 'hits' : 'misses';
        $key = sprintf('sso:claims:%s:%s:%s:%s', $suffix, $caller->tenantId, $caller->caller, $bucket);

        $this->increment($store, $key, $now->addMinutes(2));

        $hitCount = $this->getCounter($store, sprintf('sso:claims:hits:%s:%s:%s', $caller->tenantId, $caller->caller, $bucket));
        $missCount = $this->getCounter($store, sprintf('sso:claims:misses:%s:%s:%s', $caller->tenantId, $caller->caller, $bucket));
        $total = $hitCount + $missCount;

        $hitRatioThreshold = (float) config('sso.claims.alarm_hit_ratio_threshold', 0.98);

        if ($total >= 10 && $total > 0 && ($hitCount / $total) >= $hitRatioThreshold) {
            Log::warning('sso.claims.hit_ratio_alarm', [
                'tenant_id' => $caller->tenantId,
                'caller' => $caller->caller,
                'hits' => $hitCount,
                'misses' => $missCount,
            ]);
        }

        $missSpikeThreshold = max(1, (int) config('sso.claims.alarm_miss_spike_threshold', 100));

        if ($missCount >= $missSpikeThreshold) {
            Log::warning('sso.claims.miss_spike_alarm', [
                'tenant_id' => $caller->tenantId,
                'caller' => $caller->caller,
                'hits' => $hitCount,
                'misses' => $missCount,
            ]);
        }
    }

    private function increment(Repository $store, string $key, \DateTimeInterface $ttl): int
    {
        $store->add($key, 0, $ttl);
        $value = $store->increment($key);

        return is_numeric($value) ? (int) $value : 0;
    }

    private function getCounter(Repository $store, string $key): int
    {
        $value = $store->get($key, 0);

        return is_numeric($value) ? (int) $value : 0;
    }
}
