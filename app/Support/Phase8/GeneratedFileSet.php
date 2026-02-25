<?php

declare(strict_types=1);

namespace App\Support\Phase8;

final class GeneratedFileSet
{
    /**
     * @param  array<string, string>  $newFiles
     * @param  array<string, string>  $replacements
     */
    public function __construct(
        public array $newFiles,
        public array $replacements,
    ) {}
}
