<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Phase7\SecurityAlarmLogger;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class RejectAdminQuerySecrets
{
    public function __construct(
        private readonly SecurityAlarmLogger $securityAlarm,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->isAdminRequest($request)) {
            return $next($request);
        }

        $blockedKeys = array_values(array_filter(array_map(
            static fn (string $key): string => strtolower(trim($key)),
            (array) config('phase7.admin.blocked_query_keys', []),
        ), static fn (string $key): bool => $key !== ''));

        foreach ($request->query() as $key => $value) {
            if (! is_string($key) || ! in_array(strtolower(trim($key)), $blockedKeys, true)) {
                continue;
            }

            $request->session()->invalidate();
            $request->session()->regenerateToken();

            $this->securityAlarm->record('admin_query_secret_rejected', [
                'path' => (string) $request->path(),
                'query_key' => strtolower(trim($key)),
            ]);

            return response()->json([
                'code' => 'ADMIN_QUERY_REJECTED',
                'message' => 'Sensitive query parameters are not accepted in admin surfaces.',
            ], 400);
        }

        return $next($request);
    }

    private function isAdminRequest(Request $request): bool
    {
        $path = trim((string) $request->path(), '/');

        return $path === 'admin' || str_starts_with($path, 'admin/');
    }
}
