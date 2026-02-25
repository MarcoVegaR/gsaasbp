<?php

declare(strict_types=1);

use App\Http\Controllers\Phase4\BillingWebhookController;
use App\Http\Controllers\Phase4\InviteController;
use App\Http\Controllers\Phase4\TenantAuditLogController;
use App\Http\Controllers\Phase4\TenantBillingController;
use App\Http\Controllers\Phase4\TenantEventIngestController;
use App\Http\Controllers\Phase4\TenantRbacController;
use App\Http\Controllers\Phase7\TenantImpersonationTerminateController;
use App\Http\Controllers\Phase6\TenantNotificationDestroyController;
use App\Http\Controllers\Phase6\TenantNotificationIndexController;
use App\Http\Controllers\Phase6\TenantNotificationMarkReadController;
use App\Http\Controllers\Sso\Tenant\ConsumeSsoController;
use App\Http\Middleware\ResolveS2sCaller;
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
        ->middleware(['phase5.tenant.active', 'throttle:sso-consume'])
        ->name('tenant.sso.consume');

    Route::post('/tenant/events/ingest', TenantEventIngestController::class)
        ->middleware([ResolveS2sCaller::class, 'phase5.tenant.active'])
        ->name('tenant.phase4.events.ingest');

    Route::post('/tenant/billing/webhooks/{provider}', BillingWebhookController::class)
        ->middleware('phase5.tenant.active')
        ->name('tenant.phase4.billing.webhook');

    Route::get('/', function () {
        return 'This is your multi-tenant application. The id of the current tenant is '.tenant('id');
    });

    Route::middleware(['auth', 'verified', 'phase7.impersonation.enforce'])->group(function () {
        Route::get('/tenant/dashboard', function () {
            return Inertia::render('tenant/dashboard');
        })->name('tenant.dashboard');

        Route::get('/tenant/settings', function () {
            return Inertia::render('tenant/settings');
        })->name('tenant.settings');

        Route::post('/tenant/impersonation/terminate', TenantImpersonationTerminateController::class)
            ->middleware('phase5.tenant.active')
            ->name('tenant.phase7.impersonation.terminate');

        Route::prefix('/tenant/notifications')
            ->name('tenant.phase6.notifications.')
            ->middleware('phase5.tenant.active')
            ->group(function (): void {
                Route::get('/', TenantNotificationIndexController::class)
                    ->name('index');

                Route::patch('/{notificationId}/read', TenantNotificationMarkReadController::class)
                    ->name('read');

                Route::delete('/{notificationId}', TenantNotificationDestroyController::class)
                    ->name('destroy');
            });

        Route::middleware(['phase5.tenant.active', 'phase4.profile.fresh'])->group(function (): void {
            Route::post('/tenant/invites', [InviteController::class, 'store'])
                ->middleware('phase4.entitlement:tenant.invites')
                ->name('tenant.phase4.invites.store');

            Route::get('/tenant/rbac/members', [TenantRbacController::class, 'index'])
                ->middleware('phase4.entitlement:tenant.rbac')
                ->name('tenant.phase4.rbac.members.index');

            Route::post('/tenant/rbac/members/{member}/roles', [TenantRbacController::class, 'update'])
                ->middleware(['phase4.rbac.step-up', 'phase4.entitlement:tenant.rbac'])
                ->name('tenant.phase4.rbac.members.roles.update');

            Route::get('/tenant/billing', [TenantBillingController::class, 'show'])
                ->middleware('phase4.entitlement:tenant.billing')
                ->name('tenant.phase4.billing.show');

            Route::post('/tenant/billing/reconcile', [TenantBillingController::class, 'reconcile'])
                ->middleware('phase4.entitlement:tenant.billing.reconcile')
                ->name('tenant.phase4.billing.reconcile');

            Route::get('/tenant/audit-logs', [TenantAuditLogController::class, 'index'])
                ->middleware('phase4.entitlement:tenant.audit')
                ->name('tenant.phase4.audit.index');

            Route::post('/tenant/audit-logs/export', [TenantAuditLogController::class, 'export'])
                ->middleware('phase4.entitlement:tenant.audit.export')
                ->name('tenant.phase4.audit.export');
        });
    });
});
