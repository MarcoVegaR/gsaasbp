<?php

declare(strict_types=1);

namespace App\Http\Controllers\Phase4;

use App\Http\Controllers\Controller;
use App\Jobs\ExportTenantAuditLogJob;
use App\Models\ActivityLog;
use App\Models\User;
use App\Support\Phase4\Audit\ForensicAuditRepository;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class TenantAuditLogController extends Controller
{
    public function __construct(
        private readonly ForensicAuditRepository $repository,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $tenant = tenant();
        abort_if($tenant === null, 403, 'Forbidden.');

        $actor = $request->user();
        abort_unless($actor instanceof User, 401, 'Unauthorized.');

        $this->authorize('viewAudit', $tenant);

        [$from, $to] = $this->resolveWindow($request);

        $filters = [
            'event' => $request->query('event'),
            'request_id' => $request->query('request_id'),
            'actor_id' => $request->query('actor_id'),
        ];

        try {
            $paginator = $this->repository->paginated(
                tenantId: (string) $tenant->getTenantKey(),
                from: $from,
                to: $to,
                filters: $filters,
                perPage: (int) $request->integer('per_page', 25),
            );
        } catch (InvalidArgumentException) {
            return response()->json([
                'code' => 'INVALID_AUDIT_WINDOW',
                'message' => 'Invalid forensic query time window.',
            ], 422);
        }

        return response()->json([
            'data' => collect($paginator->items())
                ->map(static fn (ActivityLog $entry): array => [
                    'id' => (int) $entry->getKey(),
                    'event' => (string) $entry->event,
                    'request_id' => $entry->request_id,
                    'actor_id' => $entry->actor_id,
                    'hmac_kid' => $entry->hmac_kid,
                    'properties' => $entry->properties,
                    'created_at' => optional($entry->created_at)->toIso8601String(),
                ])
                ->values()
                ->all(),
            'meta' => [
                'from' => $from->toIso8601String(),
                'to' => $to->toIso8601String(),
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function export(Request $request): JsonResponse
    {
        $tenant = tenant();
        abort_if($tenant === null, 403, 'Forbidden.');

        $actor = $request->user();
        abort_unless($actor instanceof User, 401, 'Unauthorized.');

        $this->authorize('viewAudit', $tenant);

        [$from, $to] = $this->resolveWindow($request);

        $validated = $request->validate([
            'event' => ['nullable', 'string', 'max:191'],
            'request_id' => ['nullable', 'string', 'max:255'],
            'actor_id' => ['nullable', 'integer', 'min:1'],
        ]);

        ExportTenantAuditLogJob::dispatch(
            tenantId: (string) $tenant->getTenantKey(),
            actorId: (int) $actor->getAuthIdentifier(),
            fromIso8601: $from->toIso8601String(),
            toIso8601: $to->toIso8601String(),
            filters: [
                'event' => $validated['event'] ?? null,
                'request_id' => $validated['request_id'] ?? null,
                'actor_id' => $validated['actor_id'] ?? null,
            ],
        );

        return response()->json([
            'status' => 'accepted',
        ], 202);
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function resolveWindow(Request $request): array
    {
        $defaultWindowHours = max(1, (int) config('phase4.audit.default_window_hours', 24));
        $to = $this->parseDate((string) $request->input('to', ''), CarbonImmutable::now());
        $from = $this->parseDate((string) $request->input('from', ''), $to->subHours($defaultWindowHours));

        return [$from, $to];
    }

    private function parseDate(string $value, CarbonImmutable $fallback): CarbonImmutable
    {
        $candidate = trim($value);

        if ($candidate === '') {
            return $fallback;
        }

        return CarbonImmutable::parse($candidate);
    }
}
