<?php

declare(strict_types=1);

namespace App\Http\Controllers\Phase7;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Support\Phase7\ForensicAuditExplorerService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

final class AdminForensicAuditIndexController extends Controller
{
    public function __invoke(Request $request, ForensicAuditExplorerService $explorer): JsonResponse
    {
        $this->authorize('platform.audit.view');

        $validated = $request->validate([
            'tenant_id' => ['nullable', 'string', 'max:255'],
            'event' => ['nullable', 'string', 'max:191'],
            'request_id' => ['nullable', 'string', 'max:255'],
            'actor_id' => ['nullable', 'integer', 'min:1'],
            'from' => ['required', 'date'],
            'to' => ['required', 'date'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $from = CarbonImmutable::parse((string) $validated['from']);
        $to = CarbonImmutable::parse((string) $validated['to']);

        try {
            $paginator = $explorer->paginated(
                from: $from,
                to: $to,
                filters: [
                    'tenant_id' => $validated['tenant_id'] ?? null,
                    'event' => $validated['event'] ?? null,
                    'request_id' => $validated['request_id'] ?? null,
                    'actor_id' => $validated['actor_id'] ?? null,
                ],
                perPage: isset($validated['per_page']) ? (int) $validated['per_page'] : 50,
            );
        } catch (InvalidArgumentException) {
            return response()->json([
                'code' => 'INVALID_AUDIT_WINDOW',
                'message' => 'Invalid forensic window.',
            ], 422);
        }

        return response()->json([
            'data' => collect($paginator->items())
                ->map(static fn (ActivityLog $entry): array => [
                    'id' => (int) $entry->getKey(),
                    'tenant_id' => (string) $entry->tenant_id,
                    'event' => (string) $entry->event,
                    'request_id' => $entry->request_id,
                    'actor_id' => $entry->actor_id,
                    'hmac_kid' => $entry->hmac_kid,
                    'properties' => $entry->properties,
                    'created_at' => optional($entry->created_at)->toIso8601String(),
                    'redacted' => $entry->hmac_kid !== null && trim((string) $entry->hmac_kid) !== '',
                ])
                ->values()
                ->all(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'from' => $from->toIso8601String(),
                'to' => $to->toIso8601String(),
            ],
        ]);
    }
}
