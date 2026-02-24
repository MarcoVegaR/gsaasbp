<?php

declare(strict_types=1);

namespace App\Support\Phase4\Events;

final class TenantEventSignature
{
    public function verify(TenantEventEnvelope $envelope): bool
    {
        return hash_equals($this->sign($envelope->signedPayload()), $envelope->signature);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function sign(array $payload): string
    {
        return hash_hmac('sha256', $this->canonicalize($payload), $this->secret());
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function canonicalize(array $payload): string
    {
        $normalized = $this->normalize($payload);

        return (string) json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @return array<string, mixed>
     */
    private function normalize(array $value): array
    {
        ksort($value);

        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->isAssoc($item)
                    ? $this->normalize($item)
                    : array_map(fn (mixed $entry): mixed => is_array($entry) ? $this->normalize($entry) : $entry, $item);
            }
        }

        return $value;
    }

    /**
     * @param  array<int|string, mixed>  $array
     */
    private function isAssoc(array $array): bool
    {
        return array_keys($array) !== range(0, count($array) - 1);
    }

    private function secret(): string
    {
        return (string) config('phase4.s2s_events.shared_secret', '');
    }
}
