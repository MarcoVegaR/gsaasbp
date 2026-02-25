<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlatformHardDeleteApproval extends Model
{
    protected $primaryKey = 'approval_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'approval_id',
        'tenant_id',
        'requested_by_platform_user_id',
        'approved_by_platform_user_id',
        'executor_platform_user_id',
        'reason_code',
        'signature',
        'expires_at',
        'consumed_at',
    ];

    protected function casts(): array
    {
        return [
            'requested_by_platform_user_id' => 'integer',
            'approved_by_platform_user_id' => 'integer',
            'executor_platform_user_id' => 'integer',
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
        ];
    }
}
