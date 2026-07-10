<?php

declare(strict_types=1);

namespace Vortos\Observability\Collector;

use InvalidArgumentException;

/**
 * Configuration for the log-aggregation pipeline (Block 16, §3.3):
 * filelog receiver (Docker container logs) → collector-side redaction → optional
 * sampling → batch → persistent queue → off-host OTLP exporter.
 *
 * The filelog receiver rides the *same* sidecar the metrics/traces pipelines already
 * use, so shipping application logs off-host adds no new infrastructure — only a
 * read-only mount of the container-log directory.
 */
final readonly class LogPipelineConfig
{
    /** The Docker json-file log directory globs the collector tails by default. */
    public const DEFAULT_INCLUDE_PATHS = ['/var/lib/docker/containers/*/*.log'];

    public const START_AT_BEGINNING = 'beginning';
    public const START_AT_END = 'end';

    /**
     * @param list<string> $includePaths Container json-log file globs the filelog receiver tails
     * @param float        $sampleRatio  Fraction of log records forwarded (1.0 = ship every record).
     *                                   Applied by a probabilistic sampler across ALL records, so keep
     *                                   this at 1.0 unless log volume/cost forces trimming — logs are
     *                                   the forensic record and dropped records are unrecoverable.
     * @param string       $startAt      Where the filelog receiver begins reading a file with no stored
     *                                   offset: `end` (default — only new lines, avoids re-ingesting the
     *                                   whole existing container-log history on first start) or `beginning`.
     *                                   The persistent `storage` checkpoint means this only ever affects
     *                                   brand-new files; established files always resume from checkpoint.
     */
    public function __construct(
        public array $includePaths = self::DEFAULT_INCLUDE_PATHS,
        public LogRedactionPolicy $redaction = new LogRedactionPolicy(),
        public float $sampleRatio = 1.0,
        public string $storageDir = '/var/lib/otelcol/storage',
        public string $startAt = self::START_AT_END,
    ) {
        if ($includePaths === []) {
            throw new InvalidArgumentException('LogPipelineConfig includePaths must not be empty.');
        }
        foreach ($includePaths as $path) {
            if ($path === '') {
                throw new InvalidArgumentException('LogPipelineConfig includePaths entries must be non-empty.');
            }
        }
        if ($sampleRatio < 0.0 || $sampleRatio > 1.0) {
            throw new InvalidArgumentException('LogPipelineConfig sampleRatio must be between 0.0 and 1.0.');
        }
        if ($storageDir === '') {
            throw new InvalidArgumentException('LogPipelineConfig storageDir must be non-empty.');
        }
        if (!in_array($startAt, [self::START_AT_BEGINNING, self::START_AT_END], true)) {
            throw new InvalidArgumentException('LogPipelineConfig startAt must be "beginning" or "end".');
        }
    }
}
