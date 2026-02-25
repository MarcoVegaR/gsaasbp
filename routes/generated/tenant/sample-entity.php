<?php

declare(strict_types=1);

use App\Http\Controllers\Generated\Tenant\SampleEntity\SampleEntityController;
use App\Models\Generated\Tenant\SampleEntity;
use Illuminate\Support\Facades\Route;

Route::tenantResource(
    'tenant/modules/sample-entities',
    SampleEntityController::class,
    SampleEntity::class,
    'sampleEntity',
    'tenant.generated.sample-entity',
    'tenant.sample-entity',
    ['phase5.tenant.active'],
);
