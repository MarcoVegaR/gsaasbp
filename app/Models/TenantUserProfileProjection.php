<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantUserProfileProjection extends Model
{
    use BelongsToTenant;
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'central_user_id',
        'display_name',
        'avatar_url',
        'mfa_status',
        'profile_version',
        'last_synced_at',
        'stale_after',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'mfa_status' => 'boolean',
            'profile_version' => 'integer',
            'last_synced_at' => 'datetime',
            'stale_after' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'central_user_id');
    }
}
