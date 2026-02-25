<?php

declare(strict_types=1);

use App\Http\Controllers\Phase5\AdminDashboardController;
use App\Http\Controllers\Phase5\AdminHardDeleteTenantController;
use App\Http\Controllers\Phase5\AdminIssueStepUpCapabilityController;
use App\Http\Controllers\Phase5\AdminTelemetryAnalyticsController;
use App\Http\Controllers\Phase5\AdminTelemetryCollectorPreviewController;
use App\Http\Controllers\Phase5\AdminTenantStatusController;
use App\Http\Controllers\Phase7\AdminBillingEventIndexController;
use App\Http\Controllers\Phase7\AdminBillingReconcileController;
use App\Http\Controllers\Phase7\AdminForensicAuditIndexController;
use App\Http\Controllers\Phase7\AdminForensicExportDownloadController;
use App\Http\Controllers\Phase7\AdminForensicExportRequestController;
use App\Http\Controllers\Phase7\AdminForensicExportTokenController;
use App\Http\Controllers\Phase7\AdminHardDeleteApprovalController;
use App\Http\Controllers\Phase7\AdminImpersonationIssueController;
use App\Http\Controllers\Phase7\AdminImpersonationTerminateController;
use App\Http\Controllers\Phase7\AdminPanelController;
use App\Http\Controllers\Phase7\AdminTenantIndexController;
use Illuminate\Support\Facades\Route;

Route::prefix('admin')
    ->name('admin.')
    ->middleware([
        'phase5.platform.cookie',
        'phase5.platform.guard',
        'phase7.admin.query-secrets',
        'phase7.admin.session-fresh',
        'phase7.admin.frame-guards',
        'auth:platform',
        'verified',
        'phase7.admin.origin',
        'phase5.impersonation.block-mutations',
    ])
    ->group(function (): void {
        Route::get('panel', AdminPanelController::class)
            ->name('panel');

        Route::get('dashboard', AdminDashboardController::class)
            ->name('dashboard');

        Route::post('step-up/capabilities', AdminIssueStepUpCapabilityController::class)
            ->name('step-up.capabilities.issue');

        Route::post('tenants/status', AdminTenantStatusController::class)
            ->name('tenants.status.update');

        Route::get('tenants', AdminTenantIndexController::class)
            ->name('tenants.index');

        Route::post('tenants/{tenantId}/hard-delete-approvals', AdminHardDeleteApprovalController::class)
            ->name('tenants.hard-delete-approvals.issue');

        Route::delete('tenants/{tenantId}', AdminHardDeleteTenantController::class)
            ->middleware('phase5.step-up:platform.tenants.hard-delete')
            ->name('tenants.hard-delete');

        Route::get('telemetry/analytics', AdminTelemetryAnalyticsController::class)
            ->middleware('throttle:phase5-analytics')
            ->name('telemetry.analytics.index');

        Route::post('telemetry/collector-preview', AdminTelemetryCollectorPreviewController::class)
            ->middleware('throttle:phase5-analytics')
            ->name('telemetry.collector.preview');

        Route::get('forensics/audit', AdminForensicAuditIndexController::class)
            ->name('forensics.audit.index');

        Route::post('forensics/exports', AdminForensicExportRequestController::class)
            ->middleware('phase5.step-up:platform.audit.export')
            ->name('forensics.exports.request');

        Route::post('forensics/exports/{exportId}/token', AdminForensicExportTokenController::class)
            ->middleware('phase5.step-up:platform.audit.export')
            ->name('forensics.exports.token.issue');

        Route::post('forensics/exports/download', AdminForensicExportDownloadController::class)
            ->name('forensics.exports.download');

        Route::get('billing/events', AdminBillingEventIndexController::class)
            ->name('billing.events.index');

        Route::post('billing/reconcile', AdminBillingReconcileController::class)
            ->middleware('phase5.step-up:platform.billing.reconcile')
            ->name('billing.reconcile');

        Route::post('impersonation/issue', AdminImpersonationIssueController::class)
            ->middleware('phase5.step-up:platform.impersonation.issue')
            ->name('impersonation.issue');

        Route::post('impersonation/terminate', AdminImpersonationTerminateController::class)
            ->name('impersonation.terminate');
    });
