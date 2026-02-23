<?php

declare(strict_types=1);

namespace Tests\Support;

use RuntimeException;

class EarlyIdentificationTenantProbe
{
    public function __construct()
    {
        if (! tenancy()->initialized) {
            throw new RuntimeException('Tenancy was not initialized before dependency resolution.');
        }
    }
}
