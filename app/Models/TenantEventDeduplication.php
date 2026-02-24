<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TenantEventDeduplication extends Model
{
    use BelongsToTenant;
    use HasFactory;

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
        'event_name',
        'schema_version',
        'retry_count',
        'processed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'retry_count' => 'integer',
            'processed_at' => 'datetime',
        ];
    }
}
