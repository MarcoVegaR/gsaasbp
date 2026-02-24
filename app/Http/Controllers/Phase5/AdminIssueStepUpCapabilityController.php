<?php

declare(strict_types=1);

namespace App\Http\Controllers\Phase5;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Phase5\StepUpCapabilityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AdminIssueStepUpCapabilityController extends Controller
{
    public function __invoke(Request $request, StepUpCapabilityService $stepUp): JsonResponse
    {
        $actor = $request->user('platform');
        abort_unless($actor instanceof User, 401, 'Unauthorized.');

        $this->authorize('platform.step-up.issue');

        $validated = $request->validate([
            'scope' => ['required', 'string', 'max:191'],
            'ttl_seconds' => ['nullable', 'integer', 'min:60', 'max:3600'],
        ]);

        $scope = trim((string) $validated['scope']);
        $allowedScopes = array_values(array_filter(array_map(
            static fn (string $value): string => trim($value),
            (array) config('phase5.step_up.allowed_scopes', ['platform.tenants.hard-delete']),
        ), static fn (string $value): bool => $value !== ''));

        if (! in_array($scope, $allowedScopes, true)) {
            return response()->json([
                'code' => 'INVALID_STEP_UP_SCOPE',
            ], 422);
        }

        $capability = $stepUp->issue(
            platformUserId: (int) $actor->getAuthIdentifier(),
            sessionId: $request->session()->getId(),
            deviceFingerprint: $this->deviceFingerprint($request),
            scope: $scope,
            ipAddress: $request->ip(),
            ttlSeconds: isset($validated['ttl_seconds']) ? (int) $validated['ttl_seconds'] : null,
        );

        return response()->json([
            'status' => 'issued',
            'capability_id' => $capability->getKey(),
            'scope' => (string) $capability->scope,
            'expires_at' => optional($capability->expires_at)->toIso8601String(),
        ], 201);
    }

    private function deviceFingerprint(Request $request): string
    {
        return hash('sha256', implode('|', [
            (string) $request->userAgent(),
            (string) $request->header('Accept-Language', ''),
        ]));
    }
}
