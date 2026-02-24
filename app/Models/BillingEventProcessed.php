<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BillingEventProcessed extends Model
{
    use BelongsToTenant;
    use HasFactory;

    /**
     * @var string
     */
    protected $table = 'billing_events_processed';

    /**
     * @var string
     */
    protected $primaryKey = 'event_id';

    /**
     * @var bool
     */
    public $incrementing = false;

    /**
     * @var string
     */
    protected $keyType = 'string';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'event_id',
        'tenant_id',
        'provider',
        'outcome_hash',
        'provider_object_version',
        'processed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'provider_object_version' => 'integer',
            'processed_at' => 'datetime',
        ];
    }
}
