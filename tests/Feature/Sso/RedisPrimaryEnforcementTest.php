<?php

declare(strict_types=1);

use App\Support\Sso\SsoOneTimeTokenStore;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('sso redis one-time token store enforces primary write role', function () {
    config([
        'sso.token_store' => 'redis',
        'sso.redis.write_connection' => 'sso_write',
        'sso.redis.write_role' => 'primary',
        'database.redis.sso_write.role' => 'replica',
    ]);

    $store = new SsoOneTimeTokenStore;

    expect(fn () => $store->issue('tenant-a', 'jti-a', 30))
        ->toThrow(RuntimeException::class, 'SSO redis write connection must be primary.');
});
