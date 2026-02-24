<?php

declare(strict_types=1);

use App\Http\Controllers\Phase5\AdminDashboardController;
use App\Http\Controllers\Phase5\AdminHardDeleteTenantController;
use App\Http\Controllers\Phase5\AdminIssueStepUpCapabilityController;
use App\Http\Controllers\Phase5\AdminTelemetryAnalyticsController;
use App\Http\Controllers\Phase5\AdminTelemetryCollectorPreviewController;
use App\Http\Controllers\Phase5\AdminTenantStatusController;
use Illuminate\Support\Facades\Route;

Route::prefix('admin')
    ->name('admin.')
    ->middleware([
        'phase5.platform.cookie',
        'phase5.platform.guard',
        'auth:platform',
        'verified',
        'phase5.impersonation.block-mutations',
    ])
    ->group(function (): void {
        Route::get('dashboard', AdminDashboardController::class)
            ->name('dashboard');

        Route::post('step-up/capabilities', AdminIssueStepUpCapabilityController::class)
            ->name('step-up.capabilities.issue');

        Route::post('tenants/status', AdminTenantStatusController::class)
            ->name('tenants.status.update');

        Route::delete('tenants/{tenantId}', AdminHardDeleteTenantController::class)
            ->middleware('phase5.step-up:platform.tenants.hard-delete')
            ->name('tenants.hard-delete');

        Route::get('telemetry/analytics', AdminTelemetryAnalyticsController::class)
            ->middleware('throttle:phase5-analytics')
            ->name('telemetry.analytics.index');

        Route::post('telemetry/collector-preview', AdminTelemetryCollectorPreviewController::class)
            ->middleware('throttle:phase5-analytics')
            ->name('telemetry.collector.preview');
    });
