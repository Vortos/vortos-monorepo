<?php

declare(strict_types=1);

namespace Vortos\Backup\Console;

use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Vortos\Backup\Runtime\BackupWorker;

/**
 * R8-6 (A8): the long-running, in-container backup runtime — the framework-provided replacement for
 * host cron on a lean, containerized deploy. It fires the whole declared lifecycle (backup / retention
 * / drill) from config/backup.php; the app writes config, the framework runs the process.
 *
 * The scheduling decision lives in {@see BackupWorker::tick()} (pure, tested); this command only owns
 * the loop: tick, log, sleep, and shut down cleanly on SIGTERM/SIGINT (it finishes the in-flight tick,
 * then exits — never abandoning a lock mid-dump because ticks are sequential and self-contained).
 */
#[AsCommand(
    name: 'vortos:backup:worker',
    description: 'Run the containerized backup lifecycle (backup/retention/drill) on the declared cadences.',
)]
final class BackupWorkerCommand extends Command
{
    private bool $shouldStop = false;

    public function __construct(
        private readonly BackupWorker $worker,
        private readonly ClockInterface $clock,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('tick-seconds', null, InputOption::VALUE_REQUIRED, 'Seconds between schedule evaluations', '30');
        $this->addOption('once', null, InputOption::VALUE_NONE, 'Run a single evaluation tick and exit (for testing / manual kicks)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($this->worker->scheduleCount() === 0) {
            $output->writeln('<comment>No backup schedules declared in config/backup.php — worker has nothing to run.</comment>');

            return Command::SUCCESS;
        }

        $tickSeconds = max(1, (int) $input->getOption('tick-seconds'));
        $once = (bool) $input->getOption('once');

        $this->installSignalHandlers();

        $output->writeln(sprintf(
            '<info>vortos:backup:worker started</info> — %d schedule(s), tick=%ds%s',
            $this->worker->scheduleCount(),
            $tickSeconds,
            $once ? ' (once)' : '',
        ));

        do {
            foreach ($this->worker->tick($this->clock->now()) as $entry) {
                $output->writeln(sprintf('  <fg=gray>[%s]</> %s', $entry['schedule'], $entry['result']));
            }

            if ($once || $this->shouldStop) {
                break;
            }

            $this->interruptibleSleep($tickSeconds);
        } while (!$this->shouldStop);

        $output->writeln('<info>vortos:backup:worker stopped cleanly.</info>');

        return Command::SUCCESS;
    }

    private function installSignalHandlers(): void
    {
        if (!\function_exists('pcntl_signal')) {
            return;
        }

        pcntl_async_signals(true);
        $stop = function (): void {
            $this->shouldStop = true;
        };
        pcntl_signal(\SIGTERM, $stop);
        pcntl_signal(\SIGINT, $stop);
    }

    /** Sleep in 1s slices so a SIGTERM is honoured within a second rather than after a full tick. */
    private function interruptibleSleep(int $seconds): void
    {
        for ($i = 0; $i < $seconds && !$this->shouldStop; $i++) {
            sleep(1);
        }
    }
}
