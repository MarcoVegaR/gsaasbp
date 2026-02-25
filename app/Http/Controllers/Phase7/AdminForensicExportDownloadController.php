<?php

declare(strict_types=1);

namespace App\Http\Controllers\Phase7;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Phase7\ForensicExportService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class AdminForensicExportDownloadController extends Controller
{
    public function __invoke(Request $request, ForensicExportService $exports): StreamedResponse
    {
        $actor = $request->user('platform');
        abort_unless($actor instanceof User, 401, 'Unauthorized.');

        $this->authorize('platform.audit.export');

        $token = trim((string) (
            $request->input('token')
            ?? $request->header('X-Forensic-Export-Token', '')
        ));

        $export = $exports->consume($token, (int) $actor->getAuthIdentifier());

        if ($export === null) {
            abort(410, 'Export token expired or already consumed.');
        }

        $rows = $exports->payload($export);

        $filename = sprintf('forensic-export-%s.json', (string) $export->getKey());

        return response()->streamDownload(
            static function () use ($rows): void {
                echo json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            },
            $filename,
            [
                'Content-Type' => 'application/json',
            ],
        );
    }
}
