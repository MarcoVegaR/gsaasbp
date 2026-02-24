<?php

declare(strict_types=1);

namespace App\Http\Controllers\Phase4;

use App\Http\Controllers\Controller;
use App\Support\Phase4\Events\TenantEventEnvelope;
use App\Support\Phase4\Events\TenantEventProcessor;
use App\Support\Phase4\Events\TenantEventSignature;
use App\Support\Sso\S2sCaller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TenantEventIngestController extends Controller
{
    public function __invoke(
        Request $request,
        TenantEventSignature $signature,
        TenantEventProcessor $processor,
    ): JsonResponse {
        $caller = $request->attributes->get('sso_s2s_caller');

        abort_unless($caller instanceof S2sCaller, 401, 'Unauthorized.');

        $validated = $request->validate([
            'event_name' => ['required', 'string', 'max:255'],
            'event_id' => ['required', 'string', 'max:255'],
            'occurred_at' => ['required', 'date'],
            'tenant_id' => ['required', 'string', 'max:255'],
            'subject_id' => ['required', 'integer', 'min:1'],
            'schema_version' => ['required', 'string', 'max:32'],
            'signature' => ['required', 'string', 'max:255'],
            'retry_count' => ['required', 'integer', 'min:0'],
            'payload' => ['required', 'array'],
        ]);

        $envelope = TenantEventEnvelope::fromValidated($validated);

        if ($envelope->tenantId !== $caller->tenantId) {
            abort(403, 'Forbidden.');
        }

        if (! $signature->verify($envelope)) {
            abort(403, 'Invalid signature.');
        }

        $status = $processor->process($envelope);

        return match ($status) {
            'processed' => response()->json(['status' => 'processed']),
            'duplicate' => response()->json(['status' => 'duplicate_ignored']),
            default => response()->json(['status' => 'queued_for_replay'], 202),
        };
    }
}
