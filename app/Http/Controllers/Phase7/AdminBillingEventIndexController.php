<?php

declare(strict_types=1);

namespace App\Http\Controllers\Phase7;

use App\Http\Controllers\Controller;
use App\Support\Phase7\BillingExplorerService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

final class AdminBillingEventIndexController extends Controller
{
    public function __invoke(Request $request, BillingExplorerService $billing): JsonResponse
    {
        $this->authorize('platform.billing.view');

        $validated = $request->validate([
            'tenant_id' => ['nullable', 'string', 'max:255'],
            'event_id' => ['nullable', 'string', 'max:255'],
            'from' => ['required', 'date'],
            'to' => ['required', 'date'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $from = CarbonImmutable::parse((string) $validated['from']);
        $to = CarbonImmutable::parse((string) $validated['to']);

        try {
            $paginator = $billing->paginated(
                from: $from,
                to: $to,
                filters: [
                    'tenant_id' => $validated['tenant_id'] ?? null,
                    'event_id' => $validated['event_id'] ?? null,
                ],
                perPage: isset($validated['per_page']) ? (int) $validated['per_page'] : 50,
            );
        } catch (InvalidArgumentException) {
            return response()->json([
                'code' => 'INVALID_BILLING_WINDOW',
                'message' => 'Invalid billing window.',
            ], 422);
        }

        return response()->json([
            'data' => $paginator->items(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }
}
