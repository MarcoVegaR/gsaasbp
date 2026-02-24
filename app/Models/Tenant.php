<?php

declare(strict_types=1);

namespace App\Models;

use Stancl\Tenancy\Database\Concerns\HasDomains;
use Stancl\Tenancy\Database\Models\Tenant as BaseTenant;

class Tenant extends BaseTenant
{
    use HasDomains;

    protected $casts = [
        'data' => 'array',
        'status_changed_at' => 'datetime',
    ];

    public static function getCustomColumns(): array
    {
        return [
            'id',
            'db_connection',
            'status',
            'status_changed_at',
        ];
    }
}
