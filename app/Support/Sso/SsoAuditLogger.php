<?php

declare(strict_types=1);

namespace App\Support\Sso;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

final class SsoAuditLogger
{
    /**
     * @param  array<string, mixed>  $forensicContext
     */
    public function logConsume(
        Request $request,
        string $tenantId,
        int|string|null $userId,
        string $mode,
        string $outcome,
        array $forensicContext = [],
    ): void {
        $normalizedForensic = $this->normalizeForensicContext($forensicContext);

        Log::info('sso.consume', [
            'event' => 'sso.consume',
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'mode' => $mode,
            'outcome' => $outcome,
            'ip' => $request->ip(),
            'user_agent' => $this->sanitizeUserAgent((string) $request->userAgent()),
            ...$normalizedForensic,
        ]);
    }

    /**
     * @param  array<string, mixed>  $forensicContext
     * @return array<string, string|null>
     */
    private function normalizeForensicContext(array $forensicContext): array
    {
        $allowedKeys = [
            'actor_platform_user_id',
            'subject_user_id',
            'impersonation_ticket_id',
            'is_impersonating',
        ];

        $normalized = [];

        foreach ($allowedKeys as $key) {
            $value = $forensicContext[$key] ?? null;

            if ($value === null) {
                $normalized[$key] = null;

                continue;
            }

            $normalized[$key] = trim((string) $value);
        }

        return $normalized;
    }

    private function sanitizeUserAgent(string $userAgent): string
    {
        $sanitized = preg_replace('/[\x00-\x1F\x7F]+/', '', $userAgent);

        if (! is_string($sanitized)) {
            $sanitized = '';
        }

        return substr($sanitized, 0, 255);
    }
}
