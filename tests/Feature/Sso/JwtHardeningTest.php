<?php

declare(strict_types=1);

use App\Support\Sso\SsoJwtAssertionService;
use App\Support\Sso\SsoOneTimeTokenStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config([
        'sso.issuer' => 'http://localhost',
        'sso.audience' => 'tenant-sso',
        'sso.jwt.algorithm' => 'RS256',
        'sso.jwt.typ' => 'JWT',
        'sso.jwt.kid' => 'local-kid-1',
        'sso.jwt.allowed_kids' => ['local-kid-1'],
        'sso.jwt.allowed_crit_headers' => [],
        'sso.jwt.clock_skew_seconds' => 60,
        'sso.token_store' => 'array',
    ]);
});

test('jwt dangerous headers and kid abuse fail before one-time token consumption', function () {
    config([
        'sso.token_store' => 'redis',
        'sso.redis.write_connection' => 'sso_write',
        'sso.redis.write_role' => 'primary',
        'database.redis.sso_write.role' => 'replica',
    ]);

    $service = new SsoJwtAssertionService(new SsoOneTimeTokenStore);
    $basePayload = [
        'iss' => 'http://localhost',
        'aud' => 'tenant-sso',
        'typ' => 'JWT',
        'tenant_id' => 'tenant-a',
        'user_id' => '1',
        'jti' => 'jti-1',
        'iat' => now()->getTimestamp(),
        'nbf' => now()->getTimestamp(),
        'exp' => now()->addSeconds(30)->getTimestamp(),
    ];

    $dangerousHeaderToken = createUnsignedSsoToken([
        'alg' => 'RS256',
        'typ' => 'JWT',
        'kid' => 'local-kid-1',
        'jku' => 'https://evil.example/jwks.json',
    ], $basePayload);

    expect(fn () => $service->validateAndConsume($dangerousHeaderToken))
        ->toThrow(InvalidArgumentException::class, 'Invalid SSO assertion.');

    $kidAbuseToken = createUnsignedSsoToken([
        'alg' => 'RS256',
        'typ' => 'JWT',
        'kid' => '../../etc/passwd',
    ], $basePayload);

    expect(fn () => $service->validateAndConsume($kidAbuseToken))
        ->toThrow(InvalidArgumentException::class, 'Invalid SSO assertion.');
});

test('jwt algorithm pinning rejects alg none and hs256 assertions', function () {
    config([
        'sso.token_store' => 'redis',
        'sso.redis.write_connection' => 'sso_write',
        'sso.redis.write_role' => 'primary',
        'database.redis.sso_write.role' => 'replica',
    ]);

    $service = new SsoJwtAssertionService(new SsoOneTimeTokenStore);

    $payload = [
        'iss' => 'http://localhost',
        'aud' => 'tenant-sso',
        'typ' => 'JWT',
        'tenant_id' => 'tenant-a',
        'user_id' => '1',
        'jti' => 'jti-2',
        'iat' => now()->getTimestamp(),
        'nbf' => now()->getTimestamp(),
        'exp' => now()->addSeconds(30)->getTimestamp(),
    ];

    $algNone = createUnsignedSsoToken([
        'alg' => 'none',
        'typ' => 'JWT',
        'kid' => 'local-kid-1',
    ], $payload);

    expect(fn () => $service->validateAndConsume($algNone))
        ->toThrow(InvalidArgumentException::class, 'Invalid SSO assertion.');

    $hs256 = createUnsignedSsoToken([
        'alg' => 'HS256',
        'typ' => 'JWT',
        'kid' => 'local-kid-1',
    ], $payload);

    expect(fn () => $service->validateAndConsume($hs256))
        ->toThrow(InvalidArgumentException::class, 'Invalid SSO assertion.');
});

test('jwt clock skew accepts 60 second grace and rejects beyond it', function () {
    config([
        'sso.jwt.private_key' => ssoTestPrivateKey(),
        'sso.jwt.public_keys' => ['local-kid-1' => ssoTestPublicKey()],
    ]);

    $tokenStore = new SsoOneTimeTokenStore;
    $service = new SsoJwtAssertionService($tokenStore);

    $now = now()->getTimestamp();
    $tenantId = 'tenant-clock';

    $jtiWithinSkew = (string) Str::uuid();
    $tokenStore->issue($tenantId, $jtiWithinSkew, 30);

    $withinSkewAssertion = $service->issue([
        'iss' => 'http://localhost',
        'aud' => 'tenant-sso',
        'typ' => 'JWT',
        'tenant_id' => $tenantId,
        'user_id' => '1',
        'jti' => $jtiWithinSkew,
        'iat' => $now - 30,
        'nbf' => $now - 30,
        'exp' => $now - 30,
    ]);

    $validatedPayload = $service->validateAndConsume($withinSkewAssertion);

    expect($validatedPayload['jti'])->toBe($jtiWithinSkew);

    $jtiExpired = (string) Str::uuid();
    $tokenStore->issue($tenantId, $jtiExpired, 30);

    $expiredAssertion = $service->issue([
        'iss' => 'http://localhost',
        'aud' => 'tenant-sso',
        'typ' => 'JWT',
        'tenant_id' => $tenantId,
        'user_id' => '1',
        'jti' => $jtiExpired,
        'iat' => $now - 100,
        'nbf' => $now - 100,
        'exp' => $now - 61,
    ]);

    expect(fn () => $service->validateAndConsume($expiredAssertion))
        ->toThrow(InvalidArgumentException::class, 'Invalid SSO assertion.');
});

/**
 * @param  array<string, mixed>  $header
 * @param  array<string, mixed>  $payload
 */
function createUnsignedSsoToken(array $header, array $payload): string
{
    $encodedHeader = rtrim(strtr(base64_encode(json_encode($header, JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
    $encodedPayload = rtrim(strtr(base64_encode(json_encode($payload, JSON_THROW_ON_ERROR)), '+/', '-_'), '=');

    return $encodedHeader.'.'.$encodedPayload.'.invalid-signature';
}

function ssoTestPrivateKey(): string
{
    return <<<'PEM'
-----BEGIN PRIVATE KEY-----
MIIEvAIBADANBgkqhkiG9w0BAQEFAASCBKYwggSiAgEAAoIBAQDFdWXfbuSnle9X
piAOGieEkFnX3ZQ72Z0rW/UxYu5zzttwaFmFkNze7drd4c4lliKEdfCH81q/EddD
wFIUOlv2Ih+xKi9zOnKqlo/WwnFCWORjuKPPhd7EmyEsfcaNuYK3RM8nT5fFI0y4
xM6LMRlwtcVeqQq5NQaGTEjDKYicrYH4prme050bTl7f/07ues7nGV2AxLHoDmiU
o02ghx6+iipA/HaayUErj0NWZi/lM6EEzONHFT9hOd3isjHmsF4Ycar86qe5jDPD
1sLRfx5oQi1HQ8XlZyxcO5o7JbSsGhKAPXxBm5CZw8nx3jtuJ9cEgsyfMX5ZuNHP
XiJEvXELAgMBAAECggEAJhbpWdpoXTN9AelX0aCV8uptikiB6bGmsdCBUc+Fs+05
Q0u9yRgSoyY6zAZc379AVVDy3ybAYI8ueTFGJATF7IrUljZPBOlHaUS15nWHp4bC
N8JMRyHJwR8znQN+I6SfZH7vfuPJoQuYJyQ/u3XzNFy7//CX0vY5lfptJsiCQ8aV
OzmxQBJtmERpVAq2268ZztMdoxW2ZfBmwLDl6zKa5uAjxJlHQrlrOV13bA/+LA4C
vy6SJcrIReMAfHM6NWRkAso+YP3Xbe+d19/3FR6YK/AlYJdIJCfTYR90nMHJwg/w
DdSqJx1QlMvrdhOCuIzp7LesUjEWX4Kmzf/Wmfn7IQKBgQD7n68NRSyX7lNKLsxH
4auNm2iENcS1uwKp8mvstdhrykF73CwmQ6HaoURR8tILWVSKbiwRaPcpe3MAwe30
V0NTAfulzUescNCzoLhPxWPHYxlISjgR5a3kQKp2gQywA5kxS3S+AC3VpUvP1tls
1X3L3typ7EWWZDBUkpS2HvUw8wKBgQDI5I1PkdUTS80Uw5Xen4BKXoEryYjs/twa
TajY1cYbIWuUQaN7tz8YE3O13RcdYH4IwPblTN2weOMgC4KJSFYh5+GGHOF25PW+
b7VKzxR4I2gg9DQRFEIE0W9oko/LRdoO5RDVWFJmJVeIBHDN3blXx8c+UpWMf4lA
mIvucIiFiQKBgGvQiyAjgK0E2DlT/gHaxzgg29KyezvnCogpMGc3r6jX++EHgP9Q
QVy9dtmqMqfcOeYquMUo9aaXl7o+Xigw7870bZAoekp/+FzPQ8oiaNN5Oc8Ixied
AzpnHuMx/m64y/4cN8RlrT362pYOmBETFRiywFgqvdJn0XGbcQ7sCuFTAoGAM9KR
iXcrKiEhtDuIC7fFlmmulKcWhVxxVu+1oMn0oscKQ2JzU9S/l+xcqwtvjQp5OLTe
e+RKQ93LaVbOw68/WNvCV6BXoR4LLqcOc0/cDenEUMvuKoG5Thjgzm8QXPWV/MWm
hAKWrvbvD41ltBWAXF6SzUbsgSPdOiaf4lBxR1ECgYAY4gqhRkZ16qHf40JR/4Ia
YTF5Rio+0er7rm8bRvMkFpo/1gs8nzujGyQAEKNMFD1S//Nak5cl65kLWdeBYLOS
FsboXE6VaKRyBUuIgz99w4LQ0oLO7vwMrFI6Stlst4IsAwMqRQpBrb3PBk3wpkEm
KlJSBwfpH3flu646m6VIiQ==
-----END PRIVATE KEY-----
PEM;
}

function ssoTestPublicKey(): string
{
    return <<<'PEM'
-----BEGIN PUBLIC KEY-----
MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAxXVl327kp5XvV6YgDhon
hJBZ192UO9mdK1v1MWLuc87bcGhZhZDc3u3a3eHOJZYihHXwh/NavxHXQ8BSFDpb
9iIfsSovczpyqpaP1sJxQljkY7ijz4XexJshLH3GjbmCt0TPJ0+XxSNMuMTOizEZ
cLXFXqkKuTUGhkxIwymInK2B+Ka5ntOdG05e3/9O7nrO5xldgMSx6A5olKNNoIce
vooqQPx2mslBK49DVmYv5TOhBMzjRxU/YTnd4rIx5rBeGHGq/OqnuYwzw9bC0X8e
aEItR0PF5WcsXDuaOyW0rBoSgD18QZuQmcPJ8d47bifXBILMnzF+WbjRz14iRL1x
CwIDAQAB
-----END PUBLIC KEY-----
PEM;
}
