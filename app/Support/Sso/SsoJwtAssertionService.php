<?php

declare(strict_types=1);

namespace App\Support\Sso;

use InvalidArgumentException;
use RuntimeException;

final class SsoJwtAssertionService
{
    public function __construct(
        private readonly SsoOneTimeTokenStore $tokenStore,
    ) {}

    /**
     * @param  array<string, mixed>  $claims
     */
    public function issue(array $claims): string
    {
        $algorithm = (string) config('sso.jwt.algorithm', 'RS256');

        if ($algorithm !== 'RS256') {
            throw new RuntimeException('Unsupported SSO JWT algorithm.');
        }

        $header = [
            'alg' => $algorithm,
            'typ' => (string) config('sso.jwt.typ', 'JWT'),
            'kid' => (string) config('sso.jwt.kid', 'local-kid-1'),
        ];

        $encodedHeader = $this->base64UrlEncode(json_encode($header, JSON_THROW_ON_ERROR));
        $encodedPayload = $this->base64UrlEncode(json_encode($claims, JSON_THROW_ON_ERROR));
        $signingInput = $encodedHeader.'.'.$encodedPayload;

        $privateKey = trim((string) config('sso.jwt.private_key', ''));

        if ($privateKey === '') {
            throw new RuntimeException('Missing SSO private key.');
        }

        $signature = '';

        if (! openssl_sign($signingInput, $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Unable to sign SSO assertion.');
        }

        return $signingInput.'.'.$this->base64UrlEncode($signature);
    }

    /**
     * @return array<string, mixed>
     */
    public function validateAndConsume(string $jwt): array
    {
        $parts = explode('.', trim($jwt));

        if (count($parts) !== 3) {
            $this->fail();
        }

        [$encodedHeader, $encodedPayload, $encodedSignature] = $parts;

        $header = $this->decodeSegment($encodedHeader);

        if (! is_array($header)) {
            $this->fail();
        }

        $this->guardHeader($header);

        $payload = $this->decodeSegment($encodedPayload);

        if (! is_array($payload)) {
            $this->fail();
        }

        $this->guardSignature($header, $encodedHeader.'.'.$encodedPayload, $encodedSignature);
        $this->guardClaims($payload);

        $tenantId = $payload['tenant_id'] ?? null;
        $jti = $payload['jti'] ?? null;

        if (! is_string($tenantId) || $tenantId === '' || ! is_string($jti) || $jti === '') {
            $this->fail();
        }

        if (! $this->tokenStore->consume($tenantId, $jti)) {
            $this->fail();
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $header
     */
    private function guardHeader(array $header): void
    {
        foreach (['jku', 'x5u', 'jwk'] as $dangerousHeader) {
            if (array_key_exists($dangerousHeader, $header)) {
                $this->fail();
            }
        }

        $algorithm = $header['alg'] ?? null;

        if (! is_string($algorithm) || $algorithm !== (string) config('sso.jwt.algorithm', 'RS256')) {
            $this->fail();
        }

        $typ = $header['typ'] ?? null;

        if (! is_string($typ) || $typ !== (string) config('sso.jwt.typ', 'JWT')) {
            $this->fail();
        }

        $kid = $header['kid'] ?? null;

        if (! is_string($kid) || $kid === '') {
            $this->fail();
        }

        $allowedKids = config('sso.jwt.allowed_kids', []);

        if (! is_array($allowedKids) || ! in_array($kid, $allowedKids, true)) {
            $this->fail();
        }

        $crit = $header['crit'] ?? [];

        if (! is_array($crit)) {
            $this->fail();
        }

        $allowedCritHeaders = config('sso.jwt.allowed_crit_headers', []);

        if (! is_array($allowedCritHeaders)) {
            $allowedCritHeaders = [];
        }

        foreach ($crit as $entry) {
            if (! is_string($entry) || $entry === '' || ! in_array($entry, $allowedCritHeaders, true)) {
                $this->fail();
            }
        }
    }

    /**
     * @param  array<string, mixed>  $header
     */
    private function guardSignature(array $header, string $signingInput, string $encodedSignature): void
    {
        $kid = $header['kid'];
        $publicKey = config('sso.jwt.public_keys.'.$kid);

        if (! is_string($publicKey) || trim($publicKey) === '') {
            $this->fail();
        }

        $signature = $this->base64UrlDecode($encodedSignature);

        if ($signature === '') {
            $this->fail();
        }

        $verified = openssl_verify($signingInput, $signature, $publicKey, OPENSSL_ALGO_SHA256);

        if ($verified !== 1) {
            $this->fail();
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function guardClaims(array $payload): void
    {
        $iss = $payload['iss'] ?? null;
        $aud = $payload['aud'] ?? null;
        $typ = $payload['typ'] ?? null;

        if (! is_string($iss) || $iss !== (string) config('sso.issuer')) {
            $this->fail();
        }

        if (! is_string($aud) || $aud !== (string) config('sso.audience')) {
            $this->fail();
        }

        if (! is_string($typ) || $typ !== (string) config('sso.jwt.typ', 'JWT')) {
            $this->fail();
        }

        foreach (['exp', 'iat', 'nbf'] as $timeClaim) {
            if (! is_numeric($payload[$timeClaim] ?? null)) {
                $this->fail();
            }
        }

        $clockSkew = max(0, (int) config('sso.jwt.clock_skew_seconds', 60));
        $now = now()->getTimestamp();

        $exp = (int) $payload['exp'];
        $iat = (int) $payload['iat'];
        $nbf = (int) $payload['nbf'];

        if (($exp + $clockSkew) < $now) {
            $this->fail();
        }

        if (($nbf - $clockSkew) > $now || ($iat - $clockSkew) > $now) {
            $this->fail();
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodeSegment(string $segment): ?array
    {
        try {
            $decoded = $this->base64UrlDecode($segment);
            $json = json_decode($decoded, true, 512, JSON_THROW_ON_ERROR);

            return is_array($json) ? $json : null;
        } catch (\JsonException) {
            return null;
        }
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $value): string
    {
        $paddingLength = (4 - strlen($value) % 4) % 4;
        $padded = $value.str_repeat('=', $paddingLength);
        $decoded = base64_decode(strtr($padded, '-_', '+/'), true);

        if (! is_string($decoded)) {
            $this->fail();
        }

        return $decoded;
    }

    private function fail(): never
    {
        throw new InvalidArgumentException('Invalid SSO assertion.');
    }
}
