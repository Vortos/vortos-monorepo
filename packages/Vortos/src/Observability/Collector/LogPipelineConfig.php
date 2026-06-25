<?php

declare(strict_types=1);

namespace Vortos\Observability\Collector;

use InvalidArgumentException;

/**
 * Configuration for the log-aggregation pipeline extension (Block 16, §3.3):
 * filelog receiver → redaction → batch → persistent queue → off-host exporter.
 */
final readonly class LogPipelineConfig
{
    /**
     * @param list<string> $includePaths Container json-log file globs the filelog receiver tails
     */
    public function __construct(
        public array $includePaths,
        public LogRedactionPolicy $redaction = new LogRedactionPolicy(),
        public float $infoSampleRatio = 0.1,
        public string $storageDir = '/var/lib/otelcol/storage',
    ) {
        if ($includePaths === []) {
            throw new InvalidArgumentException('LogPipelineConfig includePaths must not be empty.');
        }
        foreach ($includePaths as $path) {
            if ($path === '') {
                throw new InvalidArgumentException('LogPipelineConfig includePaths entries must be non-empty.');
            }
        }
        if ($infoSampleRatio < 0.0 || $infoSampleRatio > 1.0) {
            throw new InvalidArgumentException('LogPipelineConfig infoSampleRatio must be between 0.0 and 1.0.');
        }
        if ($storageDir === '') {
            throw new InvalidArgumentException('LogPipelineConfig storageDir must be non-empty.');
        }
    }
}
