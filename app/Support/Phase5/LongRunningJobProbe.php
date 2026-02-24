<?php

declare(strict_types=1);

namespace App\Support\Phase5;

interface LongRunningJobProbe
{
    public function beforeIrreversibleSideEffect(string $tenantId): void;
}
