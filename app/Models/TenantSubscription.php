<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TenantSubscription extends Model
{
    use BelongsToTenant;
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'provider',
        'provider_customer_id',
        'provider_subscription_id',
        'status',
        'provider_object_version',
        'subscription_revision',
        'current_period_ends_at',
        'metadata',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'provider_object_version' => 'integer',
            'subscription_revision' => 'integer',
            'current_period_ends_at' => 'datetime',
            'metadata' => 'array',
        ];
    }
}
