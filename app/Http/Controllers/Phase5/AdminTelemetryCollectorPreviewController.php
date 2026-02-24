<?php

declare(strict_types=1);

namespace App\Http\Controllers\Phase5;

use App\Http\Controllers\Controller;
use App\Support\Phase5\Telemetry\CollectorPayloadSanitizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AdminTelemetryCollectorPreviewController extends Controller
{
    public function __invoke(Request $request, CollectorPayloadSanitizer $sanitizer): JsonResponse
    {
        $this->authorize('platform.telemetry.view');

        $validated = $request->validate([
            'resource_attributes' => ['nullable', 'array'],
            'spans' => ['nullable', 'array'],
            'metrics' => ['nullable', 'array'],
            'logs' => ['nullable', 'array'],
        ]);

        return response()->json([
            'sanitized' => $sanitizer->sanitize([
                'resource_attributes' => (array) ($validated['resource_attributes'] ?? []),
                'spans' => (array) ($validated['spans'] ?? []),
                'metrics' => (array) ($validated['metrics'] ?? []),
                'logs' => (array) ($validated['logs'] ?? []),
            ]),
        ]);
    }
}
