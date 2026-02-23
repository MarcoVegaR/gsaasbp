<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Sso\DomainCanonicalizer;
use Stancl\Tenancy\Database\Models\Domain as BaseDomain;

class Domain extends BaseDomain
{
    protected static function booted(): void
    {
        static::saving(function (self $domain): void {
            $domain->domain = DomainCanonicalizer::canonicalize((string) $domain->domain);
        });
    }
}
