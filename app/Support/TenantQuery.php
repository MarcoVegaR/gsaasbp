<?php

declare(strict_types=1);

namespace App\Support;

use App\Exceptions\TenantContextMismatchException;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class TenantQuery
{
    private function __construct(private readonly string $table) {}

    public static function table(string $table): self
    {
        return new self($table);
    }

    public function forTenant(string $tenantId): Builder
    {
        $currentTenantId = tenant()?->getTenantKey();

        if ($currentTenantId !== null && $currentTenantId !== $tenantId) {
            throw TenantContextMismatchException::forTenant($tenantId, $currentTenantId);
        }

        return DB::table($this->table)
            ->where('tenant_id', $tenantId);
    }
}
