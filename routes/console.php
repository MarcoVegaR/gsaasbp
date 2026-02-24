<?php

use App\Models\Tenant;
use App\Support\Billing\BillingService;
use App\Support\Phase4\Entitlements\EntitlementService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('phase4:billing:reconcile {tenantId} {provider=local}', function (
    string $tenantId,
    string $provider,
    EntitlementService $entitlements,
    BillingService $billingService,
): int {
    $tenant = Tenant::query()->find($tenantId);

    if (! $tenant instanceof Tenant) {
        $this->error('Tenant not found.');

        return self::FAILURE;
    }

    try {
        $entitlements->ensure($tenantId, 'tenant.billing.reconcile');
    } catch (\Illuminate\Auth\Access\AuthorizationException) {
        $this->error('BILLING_REQUIRED');

        return self::FAILURE;
    }

    $reconciled = $billingService->reconcileTenant($provider, $tenantId);

    if (! $reconciled) {
        $this->warn('No reconciliation snapshot found for tenant/provider.');

        return self::SUCCESS;
    }

    $this->info('Billing reconciliation completed.');

    return self::SUCCESS;
})->purpose('Reconcile Phase 4 billing snapshot for a tenant');
