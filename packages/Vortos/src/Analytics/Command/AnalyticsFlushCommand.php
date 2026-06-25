<?php

declare(strict_types=1);

namespace Vortos\Analytics\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Vortos\Analytics\AnalyticsInterface;
use Vortos\Analytics\Runtime\AnalyticsSpool;

/**
 * Drains the durable analytics spool (populated only when `ANALYTICS_SPOOL=1`)
 * out-of-band, forwarding each event through the privacy-filtering decorator so the
 * privacy pipeline is never bypassed even for spooled events. Safe to run when the
 * spool is empty or unconfigured — it then drains nothing.
 */
#[AsCommand(
    name: 'analytics:flush',
    description: 'Drain the durable analytics spool toward the configured driver',
)]
final class AnalyticsFlushCommand extends Command
{
    public function __construct(
        private readonly AnalyticsSpool $spool,
        private readonly AnalyticsInterface $analytics,
        private readonly int $drainBatch = 500,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Output machine-readable JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $drained = 0;

        while (!$this->spool->isEmpty()) {
            $batch = $this->spool->drain($this->drainBatch);
            if ($batch === []) {
                break;
            }

            foreach ($batch as $event) {
                $this->analytics->capture($event);
            }

            $drained += count($batch);
        }

        $this->analytics->flush();

        if ($input->getOption('json')) {
            $output->writeln((string) json_encode(['drained' => $drained], JSON_THROW_ON_ERROR));
        } else {
            $output->writeln(sprintf('Drained %d spooled analytics event(s).', $drained));
        }

        return Command::SUCCESS;
    }
}
