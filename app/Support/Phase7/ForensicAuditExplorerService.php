<?php

declare(strict_types=1);

namespace App\Support\Phase7;

use App\Models\ActivityLog;
use App\Support\SystemContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use InvalidArgumentException;

final class ForensicAuditExplorerService
{
    /**
     * @param  array<string, mixed>  $filters
     */
    public function paginated(CarbonImmutable $from, CarbonImmutable $to, array $filters = [], int $perPage = 50): LengthAwarePaginator
    {
        $this->assertWindow($from, $to);

        $tenantId = $this->cleanString($filters['tenant_id'] ?? null);

        /** @var LengthAwarePaginator $paginator */
        $paginator = SystemContext::execute(function () use ($from, $to, $filters, $perPage): LengthAwarePaginator {
            $query = $this->baseQuery($from, $to, $filters)
                ->orderByDesc('created_at');

            return $query->paginate(max(1, min(200, $perPage)), [
                'id',
                'tenant_id',
                'event',
                'request_id',
                'actor_id',
                'hmac_kid',
                'properties',
                'created_at',
            ]);
        }, purpose: 'admin.audit.logs.read', targetTenantId: $tenantId !== '' ? $tenantId : null);

        return $paginator;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<int, array<string, mixed>>
     */
    public function exportRows(CarbonImmutable $from, CarbonImmutable $to, array $filters = []): array
    {
        $this->assertWindow($from, $to);
        $maxRows = max(1, (int) config('phase7.forensics.max_export_rows', 5000));
        $tenantId = $this->cleanString($filters['tenant_id'] ?? null);

        /** @var array<int, array<string, mixed>> $rows */
        $rows = SystemContext::execute(function () use ($from, $to, $filters, $maxRows): array {
            return $this->baseQuery($from, $to, $filters)
                ->orderBy('created_at')
                ->limit($maxRows)
                ->get([
                    'tenant_id',
                    'event',
                    'request_id',
                    'actor_id',
                    'hmac_kid',
                    'properties',
                    'created_at',
                ])
                ->map(static fn (ActivityLog $log): array => [
                    'tenant_id' => (string) $log->tenant_id,
                    'event' => (string) $log->event,
                    'request_id' => $log->request_id,
                    'actor_id' => $log->actor_id,
                    'hmac_kid' => $log->hmac_kid,
                    'properties' => $log->properties,
                    'created_at' => optional($log->created_at)->toIso8601String(),
                ])
                ->values()
                ->all();
        }, purpose: 'admin.audit.export.generate', targetTenantId: $tenantId !== '' ? $tenantId : null);

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function baseQuery(CarbonImmutable $from, CarbonImmutable $to, array $filters)
    {
        $query = ActivityLog::query()
            ->where('created_at', '>=', $from)
            ->where('created_at', '<', $to);

        $tenantId = $this->cleanString($filters['tenant_id'] ?? null);

        if ($tenantId !== '') {
            $query->where('tenant_id', $tenantId);
        }

        $event = $this->cleanString($filters['event'] ?? null);

        if ($event !== '') {
            $query->where('event', $event);
        }

        $requestId = $this->cleanString($filters['request_id'] ?? null);

        if ($requestId !== '') {
            $query->where('request_id', $requestId);
        }

        if (is_numeric($filters['actor_id'] ?? null)) {
            $query->where('actor_id', (int) $filters['actor_id']);
        }

        return $query;
    }

    private function assertWindow(CarbonImmutable $from, CarbonImmutable $to): void
    {
        if ($from->greaterThanOrEqualTo($to)) {
            throw new InvalidArgumentException('Invalid forensic window.');
        }

        $maxWindowDays = max(1, (int) config('phase7.forensics.max_window_days', 30));

        if ($from->diffInDays($to) > $maxWindowDays) {
            throw new InvalidArgumentException('Forensic window exceeds allowed range.');
        }
    }

    private function cleanString(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
    }
}
