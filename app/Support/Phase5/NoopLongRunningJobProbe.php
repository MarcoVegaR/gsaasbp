<?php

declare(strict_types=1);

namespace App\Support\Phase5;

final class NoopLongRunningJobProbe implements LongRunningJobProbe
{
    public function beforeIrreversibleSideEffect(string $tenantId): void
    {
        // no-op by default
    }
}
