<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantNotification extends Model
{
    use BelongsToTenant;
    use HasFactory;

    /**
     * @var string
     */
    protected $table = 'notifications';

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
        'tenant_id',
        'notifiable_id',
        'event_id',
        'event_type',
        'version',
        'payload',
        'stream_key',
        'sequence',
        'is_read',
        'read_at',
        'occurred_at',
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
            'is_read' => 'boolean',
            'read_at' => 'datetime',
            'occurred_at' => 'datetime',
        ];
    }

    public function notifiable(): BelongsTo
    {
        return $this->belongsTo(User::class, 'notifiable_id');
    }
}
