<?php

declare(strict_types=1);

namespace App\Http\Controllers\Phase7;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Phase7\ForensicExportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AdminForensicExportTokenController extends Controller
{
    public function __invoke(
        Request $request,
        string $exportId,
        ForensicExportService $exports,
    ): JsonResponse {
        $actor = $request->user('platform');
        abort_unless($actor instanceof User, 401, 'Unauthorized.');

        $this->authorize('platform.audit.export');

        $token = $exports->issueDownloadToken($exportId, (int) $actor->getAuthIdentifier());

        if ($token === null) {
            return response()->json([
                'code' => 'EXPORT_NOT_FOUND',
            ], 404);
        }

        return response()->json([
            'status' => 'issued',
            'token' => $token['token'],
            'expires_at' => $token['expires_at'],
        ], 201);
    }
}
