<?php

declare(strict_types=1);

namespace App\Support\Phase7;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

final class TelemetryPrivacyBudgetService
{
    public function __construct(
        private readonly SecurityAlarmLogger $securityAlarm,
    ) {}

    public function consume(
        int $platformUserId,
        CarbonImmutable $from,
        CarbonImmutable $to,
        ?string $event = null,
    ): bool {
        $windowSeconds = max(60, (int) config('phase7.telemetry.privacy_budget.window_seconds', 3600));
        $maxCost = max(1, (int) config('phase7.telemetry.privacy_budget.max_cost_per_window', 20));
        $cost = $this->cost($from, $to, $event);

        $windowStart = (int) (floor(now()->getTimestamp() / $windowSeconds) * $windowSeconds);
        $cacheKey = implode(':', [
            'phase7',
            'privacy_budget',
            (string) $platformUserId,
            (string) $windowStart,
        ]);

        $store = Cache::store((string) config('phase7.telemetry.privacy_budget.cache_store', 'array'));
        $current = (int) $store->get($cacheKey, 0);

        if (($current + $cost) > $maxCost) {
            $this->securityAlarm->record('privacy_budget_exhausted', [
                'platform_user_id' => $platformUserId,
                'window_start' => $windowStart,
                'requested_cost' => $cost,
                'current_cost' => $current,
                'max_cost' => $maxCost,
            ]);

            return false;
        }

        $store->put($cacheKey, $current + $cost, now()->addSeconds($windowSeconds));

        return true;
    }

    private function cost(CarbonImmutable $from, CarbonImmutable $to, ?string $event): int
    {
        $hours = max(1, (int) ceil($from->diffInSeconds($to) / 3600));

        $cost = match (true) {
            $hours <= 1 => 6,
            $hours <= 6 => 4,
            $hours <= 24 => 3,
            default => 2,
        };

        if (is_string($event) && trim($event) !== '') {
            $cost++;
        }

        return $cost;
    }
}
