<?php

declare(strict_types=1);

namespace App\Support\Phase4\Audit;

final class AuditSanitizer
{
    /**
     * @param  array<string, mixed>  $properties
     * @return array{hmac_kid: string, properties: array<string, mixed>}
     */
    public function sanitize(array $properties): array
    {
        $hmacKid = (string) config('phase4.hmac.active_kid', 'kid-1');

        return [
            'hmac_kid' => $hmacKid,
            'properties' => $this->sanitizeArray($properties, $hmacKid),
        ];
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function sanitizeArray(array $values, string $hmacKid): array
    {
        $sanitized = [];

        foreach ($values as $key => $value) {
            $cleanKey = $this->sanitizeString((string) $key, 120);

            if ($cleanKey === '') {
                continue;
            }

            if ($this->isDeniedField($cleanKey)) {
                $sanitized[$cleanKey] = '[REDACTED]';

                continue;
            }

            if ($this->isHmacField($cleanKey) && ! is_array($value)) {
                $sanitized[$cleanKey] = [
                    'hmac' => $this->hmac((string) $value, $hmacKid),
                    'hmac_kid' => $hmacKid,
                ];

                continue;
            }

            if (is_array($value)) {
                $sanitized[$cleanKey] = $this->sanitizeArray($value, $hmacKid);

                continue;
            }

            if (is_bool($value) || is_int($value) || is_float($value) || $value === null) {
                $sanitized[$cleanKey] = $value;

                continue;
            }

            $sanitized[$cleanKey] = $this->sanitizeString((string) $value, (int) config('phase4.hmac.max_string_length', 512));
        }

        return $sanitized;
    }

    private function hmac(string $value, string $kid): string
    {
        $key = (string) config('phase4.hmac.keys.'.$kid, '');

        return hash_hmac('sha256', $value, $key);
    }

    private function isDeniedField(string $field): bool
    {
        return in_array($field, config('phase4.hmac.denylist_fields', []), true);
    }

    private function isHmacField(string $field): bool
    {
        return in_array($field, config('phase4.hmac.hmac_fields', []), true);
    }

    private function sanitizeString(string $value, int $limit): string
    {
        $clean = preg_replace('/[\x00-\x1F\x7F]+/', '', $value);

        if (! is_string($clean)) {
            $clean = '';
        }

        return mb_substr($clean, 0, max(1, $limit));
    }
}
