<?php

declare(strict_types=1);

namespace Vortos\Logger\Config;

/**
 * Rotation and retention policy for a file sink.
 *
 * Enabled by default for every file sink: daily rotation, gzip-compressed
 * rotated files, and bounded retention by file count, age, and total size.
 * `vortos:logs:prune` enforces maxAgeDays/maxTotalSizeMb across the whole
 * directory (RotatingFileHandler only enforces maxFiles per-base-name).
 */
final class RotationPolicy
{
    public function __construct(
        public readonly bool $enabled = true,
        public readonly int $maxFiles = 14,
        public readonly int $maxAgeDays = 30,
        public readonly int $maxTotalSizeMb = 1024,
        public readonly bool $compress = true,
    ) {}

    public static function disabled(): self
    {
        return new self(enabled: false, maxFiles: 0, maxAgeDays: 0, maxTotalSizeMb: 0, compress: false);
    }
}
