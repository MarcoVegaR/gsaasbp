<?php

declare(strict_types=1);

namespace App\Http\Controllers\Phase4;

use App\Http\Controllers\Controller;
use App\Support\Billing\BillingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BillingWebhookController extends Controller
{
    public function __invoke(Request $request, string $provider, BillingService $billingService): JsonResponse
    {
        $signatureHeader = (string) config("billing.providers.{$provider}.signature_header", 'X-Billing-Signature');
        $signature = (string) $request->headers->get($signatureHeader, '');

        $status = $billingService->handleWebhook(
            provider: $provider,
            rawPayload: (string) $request->getContent(),
            providedSignature: $signature,
            payload: $request->all(),
        );

        return response()->json([
            'status' => $status,
        ]);
    }
}
