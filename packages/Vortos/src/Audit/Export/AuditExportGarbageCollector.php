<?php

declare(strict_types=1);

namespace Vortos\Audit\Export;

use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Vortos\Audit\Observability\AuditMetrics;

/**
 * Deletes export artifacts whose retention window has passed and marks their jobs Expired.
 * Intended to run on a schedule (vortos-scheduler). Deletion is idempotent — a missing object
 * is fine — and per-job errors are logged and skipped so one bad artifact can't stall the sweep.
 */
final class AuditExportGarbageCollector
{
    public function __construct(
        private readonly AuditExportJobStoreInterface $jobs,
        private readonly AuditExportSinkInterface     $sink,
        private readonly ClockInterface               $clock,
        private readonly LoggerInterface              $logger = new NullLogger(),
        private readonly ?AuditMetrics                $metrics = null,
    ) {}

    /**
     * @return int number of artifacts collected
     */
    public function collect(int $limit = 100): int
    {
        $now       = $this->clock->now();
        $collected = 0;

        foreach ($this->jobs->findExpired($now, $limit) as $job) {
            try {
                foreach ([$job->bodyKey(), $job->manifestKey()] as $key) {
                    if ($key !== null) {
                        $this->sink->delete($key);
                    }
                }

                $job->markExpired($now);
                $this->jobs->save($job);
                $collected++;
            } catch (\Throwable $e) {
                $this->logger->error('Failed to garbage-collect an audit export artifact.', [
                    'export_id' => $job->id,
                    'exception' => $e->getMessage(),
                ]);
            }
        }

        $this->metrics?->exportsGarbageCollected($collected);

        return $collected;
    }
}
