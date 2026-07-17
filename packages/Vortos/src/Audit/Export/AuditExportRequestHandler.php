<?php

declare(strict_types=1);

namespace Vortos\Audit\Export;

use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Vortos\Audit\Observability\AuditMetrics;
use Vortos\Messaging\Attribute\AsEventHandler;

/**
 * Consumer entrypoint for async export. Runs inside the dedicated `vortos.audit.export`
 * consumer pipeline — separate from `vortos.audit` (ingestion) so a multi-million-record
 * export can never stall the append path that every request depends on.
 *
 * Loads the job, streams the trail to the object store via {@see StreamingAuditExporter}, and
 * records the terminal state on the job (Ready with the object keys + attestable facts, or
 * Failed with a summary), then notifies the requester. The transitions are idempotent and the
 * object keys are derived from the job id, so a redelivery re-runs safely (same keys, guarded
 * markReady) and an already-terminal job short-circuits. Failures are captured on the job and
 * NOT rethrown: a blind retry would just re-run a deterministic failure — the job row is the
 * durable record, and transient-vs-permanent retry policy belongs to the messaging middleware.
 */
final class AuditExportRequestHandler
{
    public function __construct(
        private readonly AuditExportJobStoreInterface   $jobs,
        private readonly StreamingAuditExporter         $exporter,
        private readonly ClockInterface                 $clock,
        private readonly int                            $artifactRetentionSeconds = 604800, // 7d
        private readonly ?AuditExportNotifierInterface  $notifier = null,
        private readonly LoggerInterface                $logger = new NullLogger(),
        private readonly ?AuditMetrics                  $metrics = null,
    ) {}

    #[AsEventHandler(handlerId: 'vortos.audit.export.run', consumer: 'vortos.audit.export', idempotent: true)]
    public function __invoke(AuditExportRequested $message): void
    {
        $job = $this->jobs->find($message->exportId);
        if ($job === null) {
            $this->logger->warning('Audit export job not found for delivered request.', ['export_id' => $message->exportId]);
            return;
        }

        if ($job->status()->isTerminal()) {
            return; // already Ready/Failed/Expired — idempotent no-op on redelivery
        }

        try {
            $job->markRunning($this->clock->now());
            $this->jobs->save($job);

            $spec   = $job->filter->toAuditQuery($job->scope, $job->tenantId);
            $result = $this->exporter->export($spec, $job->id);

            $now       = $this->clock->now();
            $expiresAt = $now->add(new \DateInterval('PT' . $this->artifactRetentionSeconds . 'S'));
            $job->markReady($result, $now, $expiresAt);
            $this->jobs->save($job);

            $this->metrics?->exportCompleted($result->recordCount);

            $this->logger->info('Audit export completed.', [
                'export_id'    => $job->id,
                'record_count' => $result->recordCount,
                'byte_size'    => $result->byteSize,
                'body_key'     => $result->bodyKey,
            ]);

            // Notify OUTSIDE the export try/catch and swallow its errors: the artifact is
            // already persisted and the job is Ready, so a flaky notification channel must
            // never flip a successful export to Failed (or crash the consumer).
            $this->notify(fn () => $this->notifier?->exportReady($job, $result), $job->id);
        } catch (\Throwable $e) {
            $job->markFailed($e->getMessage(), $this->clock->now());
            $this->jobs->save($job);

            $this->metrics?->exportFailed();

            $this->logger->error('Audit export failed.', [
                'export_id' => $job->id,
                'exception' => $e->getMessage(),
            ]);

            $this->notify(fn () => $this->notifier?->exportFailed($job, $e->getMessage()), $job->id);
        }
    }

    /** Run a notification callback, isolating its failures from the export outcome. */
    private function notify(callable $send, string $exportId): void
    {
        try {
            $send();
        } catch (\Throwable $e) {
            $this->logger->error('Audit export notification failed (export outcome unaffected).', [
                'export_id' => $exportId,
                'exception' => $e->getMessage(),
            ]);
        }
    }
}
