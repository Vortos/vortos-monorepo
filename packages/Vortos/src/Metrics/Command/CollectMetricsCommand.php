<?php

declare(strict_types=1);

namespace Vortos\Metrics\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Vortos\Metrics\Adapter\StatsDMetrics;
use Vortos\Metrics\Contract\MetricsCollectorInterface;
use Vortos\Metrics\Contract\MetricsInterface;

#[AsCommand(
    name: 'vortos:metrics:collect',
    description: 'Collect point-in-time operational gauges such as outbox and DLQ backlog',
)]
final class CollectMetricsCommand extends Command
{
    /**
     * @param iterable<MetricsCollectorInterface> $collectors
     */
    public function __construct(
        private readonly iterable $collectors,
        private readonly MetricsInterface $metrics,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $count = 0;

        foreach ($this->collectors as $collector) {
            $collector->collect();
            $count++;
        }

        if ($this->metrics instanceof StatsDMetrics) {
            $this->metrics->flush();
        }

        $io->success(sprintf('Collected %d metrics collector(s).', $count));

        return Command::SUCCESS;
    }
}

