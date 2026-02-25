<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlatformImpersonationSession extends Model
{
    protected $primaryKey = 'jti';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'jti',
        'platform_user_id',
        'target_tenant_id',
        'target_user_id',
        'reason_code',
        'fingerprint',
        'issued_at',
        'expires_at',
        'consumed_at',
        'revoked_at',
    ];

    protected function casts(): array
    {
        return [
            'platform_user_id' => 'integer',
            'target_user_id' => 'integer',
            'issued_at' => 'datetime',
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }
}
