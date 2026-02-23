<?php

declare(strict_types=1);

namespace App\Http\Controllers\Sso\Central;

use App\Http\Controllers\Controller;
use App\Support\Sso\S2sCaller;
use App\Support\Sso\SsoCodeStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RedeemBackchannelCodeController extends Controller
{
    public function __invoke(Request $request, SsoCodeStore $codeStore): JsonResponse
    {
        $caller = $request->attributes->get('sso_s2s_caller');

        abort_unless($caller instanceof S2sCaller, 401, 'Unauthorized.');

        $payload = $request->validate([
            'code' => ['required', 'string', 'min:16', 'max:255'],
            'state' => ['nullable', 'string', 'max:255'],
        ]);

        $codePayload = $codeStore->consume($caller->tenantId, (string) $payload['code']);

        abort_if($codePayload === null, 403, 'Forbidden.');

        $expectedState = (string) ($codePayload['state'] ?? '');
        $providedState = (string) ($payload['state'] ?? '');

        if ($providedState !== '' && ! hash_equals($expectedState, $providedState)) {
            abort(403, 'Forbidden.');
        }

        return response()->json([
            'tenant_id' => (string) $codePayload['tenant_id'],
            'user_id' => (int) $codePayload['user_id'],
            'redirect_path' => (string) $codePayload['redirect_path'],
            'state' => $expectedState,
            'nonce' => (string) $codePayload['nonce'],
        ]);
    }
}
