<?php

declare(strict_types=1);

namespace Vortos\Metrics\Schedule;

use Psr\Log\LoggerInterface;
use Throwable;
use Vortos\Cqrs\Attribute\AsCommandHandler;
use Vortos\Metrics\Contract\FlushableMetricsInterface;
use Vortos\Metrics\Contract\MetricsCollectorInterface;
use Vortos\Metrics\Contract\MetricsInterface;

/**
 * Handles the scheduled {@see CollectOperationalMetricsCommand}.
 *
 * Deliberately the same two steps {@see \Vortos\Metrics\Command\CollectMetricsCommand} performs —
 * run every tagged collector, then flush — so the scheduled path and the operator-invoked console
 * path observe identical values. The console command stays for ad-hoc debugging; this handler is
 * what runs unattended.
 *
 * Failure policy: a collector that throws must not abort its siblings, and must not fail the fire.
 * A backlog gauge is a monitoring signal, not a business invariant — losing one sample because the
 * DB was briefly unreachable is acceptable, but letting that exception bubble would mark the
 * schedule failed and, on a retrying scheduler, amplify load against an already-struggling DB. Each
 * collector is therefore isolated and its failure logged; the flush still runs so the collectors
 * that *did* succeed are exported.
 */
#[AsCommandHandler]
final class CollectOperationalMetricsHandler
{
    /**
     * @param iterable<MetricsCollectorInterface> $collectors
     */
    public function __construct(
        private readonly iterable $collectors,
        private readonly MetricsInterface $metrics,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    public function __invoke(CollectOperationalMetricsCommand $command): void
    {
        foreach ($this->collectors as $collector) {
            try {
                $collector->collect();
            } catch (Throwable $e) {
                $this->logger?->warning('Operational metrics collector failed; skipping this sample.', [
                    'collector' => $collector::class,
                    'exception' => $e,
                ]);
            }
        }

        // Push adapters buffer until flush. Without this the freshly-collected gauges would sit in
        // the meter provider until some unrelated lifecycle event exported them — or be discarded
        // when the scheduler resets services between fires.
        if ($this->metrics instanceof FlushableMetricsInterface) {
            $this->metrics->flush();
        }
    }
}
