<?php

declare(strict_types=1);

namespace App\Http\Controllers\Phase7;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Phase7\ForensicExportService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

final class AdminForensicExportRequestController extends Controller
{
    public function __invoke(Request $request, ForensicExportService $exports): JsonResponse
    {
        $actor = $request->user('platform');
        abort_unless($actor instanceof User, 401, 'Unauthorized.');

        $this->authorize('platform.audit.export');

        $validated = $request->validate([
            'tenant_id' => ['nullable', 'string', 'max:255'],
            'event' => ['nullable', 'string', 'max:191'],
            'request_id' => ['nullable', 'string', 'max:255'],
            'actor_id' => ['nullable', 'integer', 'min:1'],
            'reason_code' => ['required', 'string', 'max:120'],
            'from' => ['required', 'date'],
            'to' => ['required', 'date'],
        ]);

        $from = CarbonImmutable::parse((string) $validated['from']);
        $to = CarbonImmutable::parse((string) $validated['to']);

        try {
            $this->assertWindow($from, $to);

            $export = $exports->request(
                platformUserId: (int) $actor->getAuthIdentifier(),
                reasonCode: (string) $validated['reason_code'],
                from: $from,
                to: $to,
                filters: [
                    'tenant_id' => $validated['tenant_id'] ?? null,
                    'event' => $validated['event'] ?? null,
                    'request_id' => $validated['request_id'] ?? null,
                    'actor_id' => $validated['actor_id'] ?? null,
                ],
            );
        } catch (InvalidArgumentException) {
            return response()->json([
                'code' => 'INVALID_AUDIT_WINDOW',
                'message' => 'Invalid forensic window.',
            ], 422);
        }

        return response()->json([
            'status' => 'accepted',
            'export_id' => (string) $export->getKey(),
        ], 202);
    }

    private function assertWindow(CarbonImmutable $from, CarbonImmutable $to): void
    {
        if ($from->greaterThanOrEqualTo($to)) {
            throw new InvalidArgumentException('Invalid forensic window.');
        }

        if ($from->diffInDays($to) > max(1, (int) config('phase7.forensics.max_window_days', 30))) {
            throw new InvalidArgumentException('Forensic window exceeds allowed range.');
        }
    }
}
