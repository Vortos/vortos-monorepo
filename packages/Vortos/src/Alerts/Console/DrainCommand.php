<?php

declare(strict_types=1);

namespace Vortos\Alerts\Console;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;
use Vortos\Alerts\Notifier\OutboxNotifier;

/**
 * Pushes the alert delivery outbox at the real notifier backends.
 *
 * Every notifier is wrapped in an {@see OutboxNotifier} over a bounded spool, so a delivery that
 * fails — a webhook briefly down, rate-limited, or rotated — is retained for retry instead of being
 * dropped. That retention is only worth something if something drains it: with no drain running, a
 * "retryable" failure is indistinguishable from a lost alert, and the loss is necessarily silent
 * because the alerting system is the thing that just broke.
 *
 * `--loop` makes this a long-running supervised program, which is how it must run in production; the
 * one-shot form stays available for operators and CI.
 */
#[AsCommand(
    name: 'vortos:alerts:drain',
    description: 'Drain the alert delivery outbox toward real notifier backends',
)]
final class DrainCommand extends Command
{
    private bool $shouldStop = false;

    /** @param iterable<OutboxNotifier> $outboxes */
    public function __construct(
        private readonly iterable $outboxes,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('loop', null, InputOption::VALUE_NONE, 'Run continuously as a supervised worker')
            ->addOption('interval', null, InputOption::VALUE_REQUIRED, 'Seconds between drains in --loop mode', '30');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (!$input->getOption('loop')) {
            $io->success(sprintf('Drained %d notification(s) total.', $this->drainOnce($io, verbose: true)));

            return Command::SUCCESS;
        }

        $interval = max(1, (int) $input->getOption('interval'));
        $this->installSignalHandlers();
        $io->writeln(sprintf('<info>vortos:alerts:drain started</info> — interval=%ds', $interval));

        while (!$this->shouldStop) {
            try {
                $this->drainOnce($io, verbose: false);
            } catch (Throwable $e) {
                // A drain that throws must not kill the drainer. The spool is the safety net for
                // alerting; it failing and then staying down is the worst of both worlds.
                $io->writeln(sprintf('<error>drain failed: %s</error>', $e->getMessage()));
            }

            $this->interruptibleSleep($interval);
        }

        $io->writeln('<info>vortos:alerts:drain stopped cleanly.</info>');

        return Command::SUCCESS;
    }

    private function drainOnce(SymfonyStyle $io, bool $verbose): int
    {
        $total = 0;

        foreach ($this->outboxes as $outbox) {
            $results = $outbox->drain();
            $count = count($results);
            $total += $count;

            // In loop mode stay quiet when there is nothing to do: this runs every 30s forever, and
            // a line per idle pass would bury the ones that matter.
            if ($verbose || $count > 0) {
                $io->writeln(sprintf('%s: drained %d', $outbox->name(), $count));
            }
        }

        return $total;
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

    /** Sleep in 1s slices so SIGTERM is honoured within a second rather than after a full interval. */
    private function interruptibleSleep(int $seconds): void
    {
        for ($i = 0; $i < $seconds && !$this->shouldStop; $i++) {
            sleep(1);
        }
    }
}
