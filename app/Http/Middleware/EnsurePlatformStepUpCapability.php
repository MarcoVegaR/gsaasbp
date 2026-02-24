<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\Phase5\StepUpCapabilityService;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsurePlatformStepUpCapability
{
    public function __construct(
        private readonly StepUpCapabilityService $stepUp,
    ) {}

    public function handle(Request $request, Closure $next, string $scope): Response
    {
        $actor = $request->user('platform');

        if (! $actor instanceof User) {
            return response()->json(['code' => 'UNAUTHORIZED'], 401);
        }

        $capabilityId = trim((string) (
            $request->input('capability_id')
            ?? $request->header('X-Platform-Capability-Id', '')
        ));

        if ($capabilityId === '') {
            return $this->stepUpRequiredResponse();
        }

        $strictIp = (bool) config('phase5.step_up.hard_delete_strict_ip', false)
            && $scope === 'platform.tenants.hard-delete';

        $consumed = $this->stepUp->consume(
            capabilityId: $capabilityId,
            platformUserId: (int) $actor->getAuthIdentifier(),
            sessionId: $request->session()->getId(),
            deviceFingerprint: $this->deviceFingerprint($request),
            scope: $scope,
            ipAddress: $request->ip(),
            strictIp: $strictIp,
        );

        if (! $consumed) {
            return $this->stepUpRequiredResponse();
        }

        return $next($request);
    }

    private function stepUpRequiredResponse(): JsonResponse
    {
        return response()->json([
            'code' => 'STEP_UP_REQUIRED',
            'message' => 'Recent capability confirmation is required.',
        ], 423);
    }

    private function deviceFingerprint(Request $request): string
    {
        return hash('sha256', implode('|', [
            (string) $request->userAgent(),
            (string) $request->header('Accept-Language', ''),
        ]));
    }
}
