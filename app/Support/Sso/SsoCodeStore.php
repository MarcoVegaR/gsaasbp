<?php

declare(strict_types=1);

namespace App\Support\Sso;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

final class SsoCodeStore
{
    public function issue(string $tenantId, int $userId, string $redirectPath, string $state, string $nonce): string
    {
        $code = Str::random(64);

        Cache::store($this->store())
            ->put(
                $this->key($tenantId, $code),
                [
                    'tenant_id' => $tenantId,
                    'user_id' => $userId,
                    'redirect_path' => $redirectPath,
                    'state' => $state,
                    'nonce' => $nonce,
                ],
                now()->addSeconds(max(1, (int) config('sso.backchannel_code_ttl_seconds', 30))),
            );

        return $code;
    }

    /**
     * @return array<string, string|int>|null
     */
    public function consume(string $tenantId, string $code): ?array
    {
        $payload = Cache::store($this->store())->pull($this->key($tenantId, $code));

        if (! is_array($payload)) {
            return null;
        }

        if (($payload['tenant_id'] ?? null) !== $tenantId) {
            return null;
        }

        /** @var array<string, string|int> $payload */
        return $payload;
    }

    private function key(string $tenantId, string $code): string
    {
        return sprintf('sso:code:%s:%s', $tenantId, hash('sha256', $code));
    }

    private function store(): string
    {
        return (string) config('sso.token_store', 'array');
    }
}
