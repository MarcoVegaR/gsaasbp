<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlatformForensicExport extends Model
{
    protected $primaryKey = 'export_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'export_id',
        'platform_user_id',
        'reason_code',
        'filters',
        'storage_disk',
        'storage_path',
        'row_count',
        'download_token_hash',
        'download_token_expires_at',
        'downloaded_at',
    ];

    protected function casts(): array
    {
        return [
            'platform_user_id' => 'integer',
            'filters' => 'array',
            'row_count' => 'integer',
            'download_token_expires_at' => 'datetime',
            'downloaded_at' => 'datetime',
        ];
    }
}
