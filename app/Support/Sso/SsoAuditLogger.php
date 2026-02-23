<?php

declare(strict_types=1);

namespace App\Support\Sso;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

final class SsoAuditLogger
{
    public function logConsume(Request $request, string $tenantId, int|string|null $userId, string $mode, string $outcome): void
    {
        Log::info('sso.consume', [
            'event' => 'sso.consume',
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'mode' => $mode,
            'outcome' => $outcome,
            'ip' => $request->ip(),
            'user_agent' => $this->sanitizeUserAgent((string) $request->userAgent()),
        ]);
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
