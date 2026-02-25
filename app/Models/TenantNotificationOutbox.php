<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TenantNotificationOutbox extends Model
{
    use BelongsToTenant;
    use HasFactory;

    /**
     * @var string
     */
    protected $table = 'tenant_notification_outbox';

    /**
     * @var string
     */
    protected $primaryKey = 'id';

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
        'id',
        'event_id',
        'tenant_id',
        'notifiable_id',
        'event_type',
        'version',
        'payload',
        'stream_key',
        'sequence',
        'occurred_at',
        'processed_at',
        'retry_count',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'payload' => 'array',
            'sequence' => 'integer',
            'occurred_at' => 'datetime',
            'processed_at' => 'datetime',
            'retry_count' => 'integer',
        ];
    }
}
