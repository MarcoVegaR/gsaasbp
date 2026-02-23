<?php

declare(strict_types=1);

namespace App\Support\Sso;

use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;
use RuntimeException;

final class SsoOneTimeTokenStore
{
    public function issue(string $tenantId, string $jti, int $ttlSeconds): void
    {
        $key = $this->key($tenantId, $jti);

        if ($this->usesRedis()) {
            $this->redisConnection()->setex($key, max(1, $ttlSeconds), '1');

            return;
        }

        Cache::store($this->cacheStore())->put($key, '1', now()->addSeconds(max(1, $ttlSeconds)));
    }

    public function consume(string $tenantId, string $jti): bool
    {
        $key = $this->key($tenantId, $jti);

        if ($this->usesRedis()) {
            $value = $this->redisGetDel($this->redisConnection(), $key);

            return is_string($value) && $value !== '';
        }

        return Cache::store($this->cacheStore())->pull($key) !== null;
    }

    private function redisGetDel(Connection $connection, string $key): mixed
    {
        $client = $connection->client();

        if (is_object($client) && method_exists($client, 'getDel')) {
            return $client->getDel($key);
        }

        return $connection->eval(
            "local v = redis.call('GET', KEYS[1]); if v then redis.call('DEL', KEYS[1]); end; return v",
            1,
            $key,
        );
    }

    private function redisConnection(): Connection
    {
        $connectionName = (string) config('sso.redis.write_connection', 'sso_write');
        $expectedRole = strtolower((string) config('sso.redis.write_role', 'primary'));
        $configuredRole = strtolower((string) config('database.redis.'.$connectionName.'.role', 'primary'));

        if ($expectedRole !== 'primary' || $configuredRole !== 'primary') {
            throw new RuntimeException('SSO redis write connection must be primary.');
        }

        return Redis::connection($connectionName);
    }

    private function usesRedis(): bool
    {
        return (string) config('sso.token_store', 'array') === 'redis';
    }

    private function cacheStore(): string
    {
        return (string) config('sso.token_store', 'array');
    }

    private function key(string $tenantId, string $jti): string
    {
        return sprintf('sso:ott:%s:%s', $tenantId, $jti);
    }
}
