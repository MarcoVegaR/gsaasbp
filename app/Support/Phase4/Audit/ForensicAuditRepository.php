<?php

declare(strict_types=1);

namespace App\Support\Phase4\Audit;

use App\Models\ActivityLog;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;

final class ForensicAuditRepository
{
    /**
     * @param  array<string, mixed>  $filters
     */
    public function paginated(string $tenantId, CarbonInterface $from, CarbonInterface $to, array $filters = [], int $perPage = 25): LengthAwarePaginator
    {
        if ($from->greaterThanOrEqualTo($to)) {
            throw new InvalidArgumentException('Invalid time window.');
        }

        return $this->baseQuery($tenantId, $from, $to, $filters)
            ->orderByDesc('created_at')
            ->paginate(max(1, min(200, $perPage)));
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<int, array<string, mixed>>
     */
    public function exportRows(string $tenantId, CarbonInterface $from, CarbonInterface $to, array $filters = [], int $limit = 5000): array
    {
        if ($from->greaterThanOrEqualTo($to)) {
            throw new InvalidArgumentException('Invalid time window.');
        }

        return $this->baseQuery($tenantId, $from, $to, $filters)
            ->orderBy('created_at')
            ->limit(max(1, min(10000, $limit)))
            ->get(['event', 'request_id', 'actor_id', 'hmac_kid', 'properties', 'created_at'])
            ->map(static fn (ActivityLog $entry): array => [
                'event' => $entry->event,
                'request_id' => $entry->request_id,
                'actor_id' => $entry->actor_id,
                'hmac_kid' => $entry->hmac_kid,
                'properties' => $entry->properties,
                'created_at' => optional($entry->created_at)->toIso8601String(),
            ])
            ->all();
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function baseQuery(string $tenantId, CarbonInterface $from, CarbonInterface $to, array $filters): Builder
    {
        $query = ActivityLog::query()
            ->where('tenant_id', $tenantId)
            ->where('created_at', '>=', $from)
            ->where('created_at', '<', $to);

        if (is_string($filters['event'] ?? null) && $filters['event'] !== '') {
            $query->where('event', $filters['event']);
        }

        if (is_string($filters['request_id'] ?? null) && $filters['request_id'] !== '') {
            $query->where('request_id', $filters['request_id']);
        }

        if (is_numeric($filters['actor_id'] ?? null)) {
            $query->where('actor_id', (int) $filters['actor_id']);
        }

        return $query;
    }
}
