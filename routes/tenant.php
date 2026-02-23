<?php

declare(strict_types=1);

use App\Http\Controllers\Sso\Tenant\ConsumeSsoController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Stancl\Tenancy\Middleware\InitializeTenancyByDomain;
use Stancl\Tenancy\Middleware\PreventAccessFromCentralDomains;

/*
|--------------------------------------------------------------------------
| Tenant Routes
|--------------------------------------------------------------------------
|
| Here you can register the tenant routes for your application.
| These routes are loaded by the TenantRouteServiceProvider.
|
| Feel free to customize them however you want. Good luck!
|
*/

Route::middleware([
    'web',
    InitializeTenancyByDomain::class,
    PreventAccessFromCentralDomains::class,
])->group(function () {
    Route::post('/sso/consume', ConsumeSsoController::class)
        ->middleware('throttle:sso-consume')
        ->name('tenant.sso.consume');

    Route::get('/', function () {
        return 'This is your multi-tenant application. The id of the current tenant is '.tenant('id');
    });

    Route::middleware(['auth', 'verified'])->group(function () {
        Route::get('/tenant/dashboard', function () {
            return Inertia::render('tenant/dashboard');
        })->name('tenant.dashboard');

        Route::get('/tenant/settings', function () {
            return Inertia::render('tenant/settings');
        })->name('tenant.settings');
    });
});
