<?php

declare(strict_types=1);

namespace App\Http\Controllers\Phase5;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Phase5\Telemetry\AnalyticsAggregateService;
use App\Support\Phase7\TelemetryPrivacyBudgetService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

final class AdminTelemetryAnalyticsController extends Controller
{
    public function __invoke(
        Request $request,
        AnalyticsAggregateService $analytics,
        TelemetryPrivacyBudgetService $privacyBudget,
    ): JsonResponse {
        $actor = $request->user('platform');
        abort_unless($actor instanceof User, 401, 'Unauthorized.');

        $this->authorize('platform.telemetry.view');

        $validated = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'event' => ['nullable', 'string', 'max:191'],
        ]);

        $to = isset($validated['to'])
            ? CarbonImmutable::parse((string) $validated['to'])
            : CarbonImmutable::now();

        $from = isset($validated['from'])
            ? CarbonImmutable::parse((string) $validated['from'])
            : $to->subHours(24);

        if ($from->greaterThanOrEqualTo($to)) {
            throw new InvalidArgumentException('Invalid analytics range.');
        }

        $allowed = $privacyBudget->consume(
            platformUserId: (int) $actor->getAuthIdentifier(),
            from: $from,
            to: $to,
            event: isset($validated['event']) ? trim((string) $validated['event']) : null,
        );

        if (! $allowed) {
            return response()->json([
                'code' => 'PRIVACY_BUDGET_EXHAUSTED',
                'message' => 'Telemetry privacy budget exhausted for current window.',
            ], 429);
        }

        return response()->json($analytics->aggregate(
            from: $from,
            to: $to,
            event: isset($validated['event']) ? trim((string) $validated['event']) : null,
        ));
    }
}
