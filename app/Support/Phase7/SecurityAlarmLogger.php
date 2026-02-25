<?php

declare(strict_types=1);

namespace App\Support\Phase7;

use Illuminate\Support\Facades\Log;

final class SecurityAlarmLogger
{
    /**
     * @param  array<string, scalar|null>  $context
     */
    public function record(string $alarm, array $context = []): void
    {
        Log::channel('security_alarms')->warning('security_alarm', [
            'alarm' => trim($alarm),
            ...$context,
        ]);
    }
}
