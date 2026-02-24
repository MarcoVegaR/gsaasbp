<?php

declare(strict_types=1);

namespace App\Jobs\Phase5;

use App\Support\Phase5\PlatformContext;
use App\Support\Phase5\PlatformContextStore;
use App\Support\Phase5\PlatformTenantLifecycleService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;

final class SetTenantStatusJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $tenantId,
        public readonly string $status,
        public readonly ?PlatformContext $platformContext = null,
    ) {}

    public function handle(
        PlatformContextStore $platformContextStore,
        PlatformTenantLifecycleService $lifecycle,
    ): void {
        if (! $this->platformContext instanceof PlatformContext) {
            throw new RuntimeException('PLATFORM_CONTEXT_REQUIRED');
        }

        $platformContextStore->run(
            $this->platformContext,
            fn (): bool => tap(true, fn () => $lifecycle->setTenantStatus($this->tenantId, $this->status)),
        );
    }
}
