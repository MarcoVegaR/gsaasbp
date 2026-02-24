<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ModelHasRole extends Model
{
    /**
     * @var bool
     */
    public $incrementing = false;

    /**
     * @var bool
     */
    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'role_id',
        'model_type',
        'model_id',
        'tenant_id',
    ];

    public function getTable(): string
    {
        return (string) config('permission.table_names.model_has_roles', 'model_has_roles');
    }
}
