<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlatformStepUpCapability extends Model
{
    protected $primaryKey = 'capability_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'capability_id',
        'platform_user_id',
        'session_id',
        'device_fingerprint',
        'scope',
        'ip_address',
        'expires_at',
        'consumed_at',
    ];

    protected function casts(): array
    {
        return [
            'platform_user_id' => 'integer',
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
        ];
    }
}
