<?php

declare(strict_types=1);

namespace App\Support\Phase7;

use App\Models\PlatformHardDeleteApproval;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class HardDeleteApprovalService
{
    public function issue(
        string $tenantId,
        int $requestedByPlatformUserId,
        int $approvedByPlatformUserId,
        int $executorPlatformUserId,
        string $reasonCode,
    ): PlatformHardDeleteApproval {
        $cleanTenantId = trim($tenantId);
        $cleanReasonCode = trim($reasonCode);

        if ($cleanTenantId === '' || $cleanReasonCode === '') {
            throw new InvalidArgumentException('Invalid hard delete approval payload.');
        }

        if (
            $requestedByPlatformUserId === $approvedByPlatformUserId
            || $requestedByPlatformUserId === $executorPlatformUserId
            || $approvedByPlatformUserId === $executorPlatformUserId
        ) {
            throw new InvalidArgumentException('Hard delete approval requires strict four-eyes separation.');
        }

        $approvalId = (string) Str::uuid();
        $expiresAt = now()->addSeconds(max(60, (int) config('phase7.hard_delete.approval_ttl_seconds', 900)));

        $signature = $this->signature([
            $approvalId,
            $cleanTenantId,
            (string) $requestedByPlatformUserId,
            (string) $approvedByPlatformUserId,
            (string) $executorPlatformUserId,
            $cleanReasonCode,
            $expiresAt->toIso8601String(),
        ]);

        /** @var PlatformHardDeleteApproval $approval */
        $approval = PlatformHardDeleteApproval::query()->create([
            'approval_id' => $approvalId,
            'tenant_id' => $cleanTenantId,
            'requested_by_platform_user_id' => $requestedByPlatformUserId,
            'approved_by_platform_user_id' => $approvedByPlatformUserId,
            'executor_platform_user_id' => $executorPlatformUserId,
            'reason_code' => $cleanReasonCode,
            'signature' => $signature,
            'expires_at' => $expiresAt,
            'consumed_at' => null,
        ]);

        return $approval;
    }

    public function consume(string $approvalId, string $tenantId, int $executorPlatformUserId, string $reasonCode): bool
    {
        $cleanApprovalId = trim($approvalId);
        $cleanTenantId = trim($tenantId);
        $cleanReasonCode = trim($reasonCode);

        if ($cleanApprovalId === '' || $cleanTenantId === '' || $cleanReasonCode === '') {
            return false;
        }

        return (bool) DB::transaction(function () use ($cleanApprovalId, $cleanTenantId, $executorPlatformUserId, $cleanReasonCode): bool {
            /** @var PlatformHardDeleteApproval|null $approval */
            $approval = PlatformHardDeleteApproval::query()
                ->whereKey($cleanApprovalId)
                ->lockForUpdate()
                ->first();

            if (! $approval instanceof PlatformHardDeleteApproval) {
                return false;
            }

            if ($approval->consumed_at !== null || $approval->expires_at === null || $approval->expires_at->isPast()) {
                return false;
            }

            if (
                ! hash_equals((string) $approval->tenant_id, $cleanTenantId)
                || (int) $approval->executor_platform_user_id !== $executorPlatformUserId
                || ! hash_equals((string) $approval->reason_code, $cleanReasonCode)
            ) {
                return false;
            }

            $expectedSignature = $this->signature([
                (string) $approval->approval_id,
                (string) $approval->tenant_id,
                (string) $approval->requested_by_platform_user_id,
                (string) $approval->approved_by_platform_user_id,
                (string) $approval->executor_platform_user_id,
                (string) $approval->reason_code,
                $approval->expires_at->toIso8601String(),
            ]);

            if (! hash_equals((string) $approval->signature, $expectedSignature)) {
                return false;
            }

            $approval->forceFill([
                'consumed_at' => now(),
            ])->save();

            return true;
        });
    }

    /**
     * @param  array<int, string>  $parts
     */
    private function signature(array $parts): string
    {
        $secret = (string) config('phase7.hard_delete.signature_key', 'phase7-hard-delete-secret');

        return hash_hmac('sha256', implode('|', $parts), $secret);
    }
}
