<?php

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

test('tenancy business models config references tenant scoped models with tenant_id columns', function () {
    $models = config('tenancy_business_models', []);

    expect($models)->toBeArray()->not->toBeEmpty();

    foreach ($models as $modelClass) {
        expect(class_exists($modelClass))->toBeTrue();
        expect(class_uses_recursive($modelClass))->toContain(BelongsToTenant::class);

        $instance = new $modelClass;

        expect(Schema::hasColumn($instance->getTable(), 'tenant_id'))->toBeTrue();
    }
});
