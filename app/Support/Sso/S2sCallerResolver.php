<?php

declare(strict_types=1);

namespace App\Support\Sso;

use Illuminate\Http\Request;

final class S2sCallerResolver
{
    public function resolve(Request $request): ?S2sCaller
    {
        $headerName = (string) config('sso.s2s.header', 'X-S2S-Key');
        $token = trim((string) $request->headers->get($headerName, ''));

        if ($token === '') {
            return null;
        }

        $client = config('sso.s2s.clients.'.$token);

        if (! is_array($client)) {
            return null;
        }

        $tenantId = $client['tenant_id'] ?? null;
        $caller = $client['caller'] ?? null;

        if (! is_string($tenantId) || $tenantId === '' || ! is_string($caller) || $caller === '') {
            return null;
        }

        return new S2sCaller($token, $tenantId, $caller);
    }
}
