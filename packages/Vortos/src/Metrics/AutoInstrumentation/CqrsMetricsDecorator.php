<?php

declare(strict_types=1);

namespace Vortos\Metrics\AutoInstrumentation;

use Vortos\Cqrs\Command\CommandBusInterface;
use Vortos\Domain\Command\CommandInterface;
use Vortos\Metrics\Contract\MetricsInterface;

/**
 * Decorates CommandBusInterface to record per-command metrics.
 *
 * ## Metrics recorded
 *
 *   vortos_cqrs_commands_total{command}          — counter (all dispatches)
 *   vortos_cqrs_command_duration_ms{command}     — histogram (execution time)
 *   vortos_cqrs_command_failures_total{command}  — counter (exceptions only)
 *
 * ## Label value
 *
 *   'command' uses the short class name (e.g. 'RegisterUser'), not the FQCN.
 *   This keeps cardinality bounded — one label value per command type, not per instance.
 */
final class CqrsMetricsDecorator implements CommandBusInterface
{
    private const DURATION_BUCKETS = [1, 5, 10, 25, 50, 100, 250, 500, 1000, 2500];

    public function __construct(
        private readonly CommandBusInterface $inner,
        private readonly MetricsInterface $metrics,
    ) {}

    public function dispatch(CommandInterface $command): void
    {
        $commandName = substr(strrchr(get_class($command), '\\') ?: get_class($command), 1);
        $start       = hrtime(true);

        $this->metrics->counter('cqrs_commands_total', ['command' => $commandName])->increment();

        try {
            $this->inner->dispatch($command);
        } catch (\Throwable $e) {
            $this->metrics->counter('cqrs_command_failures_total', ['command' => $commandName])->increment();
            throw $e;
        } finally {
            $durationMs = (hrtime(true) - $start) / 1_000_000;
            $this->metrics->histogram('cqrs_command_duration_ms', self::DURATION_BUCKETS, [
                'command' => $commandName,
            ])->observe($durationMs);
        }
    }
}
