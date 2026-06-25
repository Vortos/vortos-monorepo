<?php

declare(strict_types=1);

namespace Vortos\Observability\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Vortos\Observability\Marker\OutboxMarkerEmitter;

/**
 * `vortos:observability:markers:drain` — drains the marker outbox (Block 16, §3.5).
 * Safe to run opportunistically (e.g. a cron tick) in addition to any inline drain.
 */
#[AsCommand(name: 'vortos:observability:markers:drain', description: 'Drain the buffered deploy-marker outbox to the configured emitter')]
final class MarkersDrainCommand extends Command
{
    public function __construct(
        private readonly OutboxMarkerEmitter $outbox,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('batch', null, InputOption::VALUE_REQUIRED, 'Max markers to drain in this run', '100');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $batch = (int) $input->getOption('batch');
        $drained = $this->outbox->drain($batch > 0 ? $batch : 100);

        $output->writeln(sprintf('<info>Drained %d marker(s).</info>', $drained));

        return Command::SUCCESS;
    }
}
