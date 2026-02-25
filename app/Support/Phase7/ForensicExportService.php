<?php

declare(strict_types=1);

namespace App\Support\Phase7;

use App\Jobs\Phase7\GenerateForensicExportJob;
use App\Models\PlatformForensicExport;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class ForensicExportService
{
    public function request(
        int $platformUserId,
        string $reasonCode,
        CarbonImmutable $from,
        CarbonImmutable $to,
        array $filters = [],
    ): PlatformForensicExport {
        $cleanReasonCode = trim($reasonCode);

        if ($cleanReasonCode === '') {
            throw new InvalidArgumentException('Export reason is required.');
        }

        $exportId = (string) Str::uuid();
        $disk = (string) config('phase7.forensics.export_disk', 'local');

        /** @var PlatformForensicExport $export */
        $export = PlatformForensicExport::query()->create([
            'export_id' => $exportId,
            'platform_user_id' => $platformUserId,
            'reason_code' => $cleanReasonCode,
            'filters' => [
                ...$filters,
                'from' => $from->toIso8601String(),
                'to' => $to->toIso8601String(),
            ],
            'storage_disk' => $disk,
            'storage_path' => '',
            'row_count' => 0,
            'download_token_hash' => null,
            'download_token_expires_at' => null,
            'downloaded_at' => null,
        ]);

        GenerateForensicExportJob::dispatch(
            exportId: $exportId,
            fromIso8601: $from->toIso8601String(),
            toIso8601: $to->toIso8601String(),
            filters: $filters,
        );

        return $export;
    }

    /**
     * @return array{token: string, expires_at: string}|null
     */
    public function issueDownloadToken(string $exportId, int $platformUserId): ?array
    {
        $cleanExportId = trim($exportId);

        if ($cleanExportId === '') {
            return null;
        }

        /** @var PlatformForensicExport|null $export */
        $export = PlatformForensicExport::query()->find($cleanExportId);

        if (! $export instanceof PlatformForensicExport || (int) $export->platform_user_id !== $platformUserId) {
            return null;
        }

        $token = Str::random(80);
        $expiresAt = now()->addSeconds(max(60, (int) config('phase7.forensics.export_token_ttl_seconds', 300)));

        $export->forceFill([
            'download_token_hash' => hash('sha256', $token),
            'download_token_expires_at' => $expiresAt,
            'downloaded_at' => null,
        ])->save();

        return [
            'token' => $token,
            'expires_at' => $expiresAt->toIso8601String(),
        ];
    }

    public function consume(string $token, int $platformUserId): ?PlatformForensicExport
    {
        $cleanToken = trim($token);

        if ($cleanToken === '') {
            return null;
        }

        $tokenHash = hash('sha256', $cleanToken);

        /** @var PlatformForensicExport|null $export */
        $export = DB::transaction(function () use ($tokenHash, $platformUserId): ?PlatformForensicExport {
            /** @var PlatformForensicExport|null $candidate */
            $candidate = PlatformForensicExport::query()
                ->where('download_token_hash', $tokenHash)
                ->whereNull('downloaded_at')
                ->where('platform_user_id', $platformUserId)
                ->lockForUpdate()
                ->first();

            if (! $candidate instanceof PlatformForensicExport) {
                return null;
            }

            if ($candidate->download_token_expires_at === null || $candidate->download_token_expires_at->isPast()) {
                return null;
            }

            $candidate->forceFill([
                'downloaded_at' => now(),
                'download_token_hash' => null,
                'download_token_expires_at' => null,
            ])->save();

            return $candidate;
        });

        return $export;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function payload(PlatformForensicExport $export): array
    {
        if (trim((string) $export->storage_path) === '') {
            return [];
        }

        $ciphertext = Storage::disk((string) $export->storage_disk)->get((string) $export->storage_path);
        $json = Crypt::decryptString((string) $ciphertext);
        $decoded = json_decode($json, true);

        return is_array($decoded) ? array_values($decoded) : [];
    }
}
