<?php

declare(strict_types=1);

namespace App\Http\Controllers\Phase4;

use App\Http\Controllers\Controller;
use App\Jobs\ReconcileTenantBillingJob;
use App\Models\TenantEntitlement;
use App\Models\TenantSubscription;
use App\Models\User;
use App\Support\Phase4\Entitlements\EntitlementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TenantBillingController extends Controller
{
    public function __construct(
        private readonly EntitlementService $entitlements,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $tenant = tenant();
        abort_if($tenant === null, 403, 'Forbidden.');

        $actor = $request->user();
        abort_unless($actor instanceof User, 401, 'Unauthorized.');

        $this->authorize('manageBilling', $tenant);

        $tenantId = (string) $tenant->getTenantKey();
        $provider = (string) config('billing.default_provider', 'local');

        $subscription = TenantSubscription::query()
            ->where('tenant_id', $tenantId)
            ->where('provider', $provider)
            ->first();

        $entitlements = TenantEntitlement::query()
            ->where('tenant_id', $tenantId)
            ->orderBy('feature')
            ->get(['feature', 'granted', 'source', 'updated_by_event_id', 'expires_at'])
            ->map(static fn (TenantEntitlement $entitlement): array => [
                'feature' => (string) $entitlement->feature,
                'granted' => (bool) $entitlement->granted,
                'source' => (string) $entitlement->source,
                'updated_by_event_id' => $entitlement->updated_by_event_id,
                'expires_at' => optional($entitlement->expires_at)->toIso8601String(),
            ])
            ->values()
            ->all();

        return response()->json([
            'provider' => $provider,
            'subscription' => $subscription !== null
                ? [
                    'status' => (string) $subscription->status,
                    'provider_object_version' => (int) $subscription->provider_object_version,
                    'subscription_revision' => (int) $subscription->subscription_revision,
                    'provider_customer_id' => $subscription->provider_customer_id,
                    'provider_subscription_id' => $subscription->provider_subscription_id,
                    'current_period_ends_at' => optional($subscription->current_period_ends_at)->toIso8601String(),
                ]
                : null,
            'entitlements' => $entitlements,
        ]);
    }

    public function reconcile(Request $request): JsonResponse
    {
        $tenant = tenant();
        abort_if($tenant === null, 403, 'Forbidden.');

        $actor = $request->user();
        abort_unless($actor instanceof User, 401, 'Unauthorized.');

        $this->authorize('manageBilling', $tenant);

        $tenantId = (string) $tenant->getTenantKey();
        $provider = (string) config('billing.default_provider', 'local');

        $this->entitlements->ensure($tenantId, 'tenant.billing.reconcile');

        ReconcileTenantBillingJob::dispatch(
            provider: $provider,
            tenantId: $tenantId,
            actorId: (int) $actor->getAuthIdentifier(),
        );

        return response()->json([
            'status' => 'accepted',
        ], 202);
    }
}
