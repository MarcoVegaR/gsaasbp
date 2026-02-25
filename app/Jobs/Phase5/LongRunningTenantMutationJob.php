<?php

declare(strict_types=1);

namespace App\Jobs\Phase5;

use App\Exceptions\TenantStatusBlockedException;
use App\Support\Phase5\JobAbortTelemetry;
use App\Support\Phase5\LongRunningJobProbe;
use App\Support\Phase5\NoopLongRunningJobProbe;
use App\Support\Phase5\TenantStatusService;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class LongRunningTenantMutationJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $tenantId,
    ) {}

    public function handle(
        TenantStatusService $tenantStatus,
        JobAbortTelemetry $jobAbortTelemetry,
        Repository $cache,
        ?LongRunningJobProbe $probe = null,
    ): void {
        try {
            $tenantStatus->ensureActive($this->tenantId);
        } catch (TenantStatusBlockedException $exception) {
            $jobAbortTelemetry->recordTenantStatusAbort($exception->status());

            return;
        }

        ($probe ?? new NoopLongRunningJobProbe)->beforeIrreversibleSideEffect($this->tenantId);

        try {
            $tenantStatus->ensureActive($this->tenantId);
        } catch (TenantStatusBlockedException $exception) {
            $jobAbortTelemetry->recordTenantStatusAbort($exception->status());

            return;
        }

        $cache->put('phase5:long-job:side-effect:'.$this->tenantId, 'written');
    }
}
