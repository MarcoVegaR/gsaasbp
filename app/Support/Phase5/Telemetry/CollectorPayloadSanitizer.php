<?php

declare(strict_types=1);

namespace App\Support\Phase5\Telemetry;

final class CollectorPayloadSanitizer
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function sanitize(array $payload): array
    {
        $redactedKeys = array_flip((array) config('phase5.telemetry.collector.redacted_keys', []));

        return [
            'resource_attributes' => $this->sanitizeAttributeBag(
                (array) ($payload['resource_attributes'] ?? []),
                (array) config('phase5.telemetry.collector.resource_allowlist', []),
                $redactedKeys,
            ),
            'spans' => $this->sanitizeSignals(
                (array) ($payload['spans'] ?? []),
                (array) config('phase5.telemetry.collector.span_attribute_allowlist', []),
                $redactedKeys,
            ),
            'metrics' => $this->sanitizeSignals(
                (array) ($payload['metrics'] ?? []),
                (array) config('phase5.telemetry.collector.metric_attribute_allowlist', []),
                $redactedKeys,
            ),
            'logs' => $this->sanitizeSignals(
                (array) ($payload['logs'] ?? []),
                (array) config('phase5.telemetry.collector.log_attribute_allowlist', []),
                $redactedKeys,
            ),
        ];
    }

    /**
     * @param  array<int, mixed>  $signals
     * @param  array<int, mixed>  $allowlist
     * @param  array<string, int>  $redactedKeys
     * @return array<int, array<string, mixed>>
     */
    private function sanitizeSignals(array $signals, array $allowlist, array $redactedKeys): array
    {
        $cleanSignals = [];

        foreach ($signals as $signal) {
            if (! is_array($signal)) {
                continue;
            }

            $attributes = $this->sanitizeAttributeBag(
                (array) ($signal['attributes'] ?? []),
                $allowlist,
                $redactedKeys,
            );

            $cleanSignals[] = [
                ...$signal,
                'attributes' => $attributes,
            ];
        }

        return $cleanSignals;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<int, mixed>  $allowlist
     * @param  array<string, int>  $redactedKeys
     * @return array<string, mixed>
     */
    private function sanitizeAttributeBag(array $attributes, array $allowlist, array $redactedKeys): array
    {
        $allowedKeys = array_flip(array_values(array_filter(array_map(
            static fn (mixed $entry): string => trim((string) $entry),
            $allowlist,
        ), static fn (string $entry): bool => $entry !== '')));

        $clean = [];

        foreach ($attributes as $key => $value) {
            $normalizedKey = trim((string) $key);

            if ($normalizedKey === '' || ! array_key_exists($normalizedKey, $allowedKeys)) {
                continue;
            }

            $clean[$normalizedKey] = array_key_exists($normalizedKey, $redactedKeys)
                ? '[REDACTED]'
                : $value;
        }

        return $clean;
    }
}
