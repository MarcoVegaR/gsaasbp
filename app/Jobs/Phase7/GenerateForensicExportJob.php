<?php

declare(strict_types=1);

namespace App\Jobs\Phase7;

use App\Models\PlatformForensicExport;
use App\Support\Phase7\ForensicAuditExplorerService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;

final class GenerateForensicExportJob implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array<string, mixed>  $filters
     */
    public function __construct(
        public readonly string $exportId,
        public readonly string $fromIso8601,
        public readonly string $toIso8601,
        public readonly array $filters = [],
    ) {}

    public function handle(ForensicAuditExplorerService $explorer): void
    {
        /** @var PlatformForensicExport|null $export */
        $export = PlatformForensicExport::query()->find($this->exportId);

        if (! $export instanceof PlatformForensicExport) {
            return;
        }

        $from = CarbonImmutable::parse($this->fromIso8601);
        $to = CarbonImmutable::parse($this->toIso8601);
        $rows = $explorer->exportRows($from, $to, $this->filters);

        $disk = trim((string) $export->storage_disk);

        if ($disk === '') {
            $disk = (string) config('phase7.forensics.export_disk', 'local');
        }

        $path = sprintf('phase7/forensics/%s.json.enc', $this->exportId);

        Storage::disk($disk)->put(
            $path,
            Crypt::encryptString((string) json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
        );

        $export->forceFill([
            'storage_disk' => $disk,
            'storage_path' => $path,
            'row_count' => count($rows),
        ])->save();
    }
}
