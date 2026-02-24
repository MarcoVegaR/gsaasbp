<?php

declare(strict_types=1);

namespace App\Support\Phase5;

use Illuminate\Support\Facades\Log;

final class JobAbortTelemetry
{
    public function recordTenantStatusAbort(string $status): void
    {
        Log::warning('job_aborted_due_to_tenant_status', [
            'metric' => 'job_aborted_due_to_tenant_status',
            'status' => $this->normalizeStatus($status),
            'environment' => app()->environment(),
        ]);
    }

    private function normalizeStatus(string $status): string
    {
        $value = trim(strtolower($status));

        return $value !== '' ? $value : 'unknown';
    }
}
