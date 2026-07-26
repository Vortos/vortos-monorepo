<?php

declare(strict_types=1);

namespace Vortos\Metrics\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;
use Vortos\Metrics\Contract\FlushableMetricsInterface;
use Vortos\Metrics\Supervisor\SupervisorMetricsReporter;
use Vortos\Metrics\Supervisor\SupervisorStatusReader;
use Psr\Log\LoggerInterface;

/**
 * Long-running collector that publishes supervisord program state as metrics.
 *
 * Runs as one more supervised program INSIDE the worker container, alongside the consumers it
 * reports on. That placement is the whole point: supervisord's control socket is a chmod-0700 unix
 * socket, so a sidecar exporter container could reach it only by downgrading it to a TCP listener
 * or sharing the socket out of the container — weakening a boundary chosen deliberately. Running
 * in-place needs no such change, and the process reports whichever deploy colour it belongs to.
 *
 * It also has to be long-lived rather than a scheduled sweep, for two reasons: the scheduler runs
 * in a different container and cannot see this socket at all, and restart counting requires
 * remembering the previous sample's pids ({@see SupervisorMetricsReporter}).
 */
#[AsCommand(
    name: 'vortos:metrics:supervisor',
    description: 'Publish supervisord program state, restarts and memory as metrics (runs inside the supervised container)',
)]
final class SupervisorMetricsCommand extends Command
{
    private const DEFAULT_INTERVAL_SECONDS = 15;

    private bool $running = true;

    public function __construct(
        private readonly SupervisorStatusReader $reader,
        private readonly SupervisorMetricsReporter $reporter,
        private readonly ?FlushableMetricsInterface $metricsFlusher = null,
        private readonly ?LoggerInterface $logger = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'interval',
            null,
            InputOption::VALUE_REQUIRED,
            'Seconds between samples',
            (string) self::DEFAULT_INTERVAL_SECONDS,
        );

        $this->addOption(
            'once',
            null,
            InputOption::VALUE_NONE,
            'Take a single sample and exit (diagnostics; restart counts need the long-running form)',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $interval = max(5, (int) $input->getOption('interval'));
        $once = (bool) $input->getOption('once');

        // Supervisor stops programs with SIGTERM. Without a handler the default action kills the
        // process mid-sample and the last interval's metrics are lost.
        if (function_exists('pcntl_signal')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM, function (): void { $this->running = false; });
            pcntl_signal(SIGINT, function (): void { $this->running = false; });
        }

        do {
            $this->sample();

            if ($once) {
                break;
            }

            // Sleep in one-second slices so a SIGTERM is honoured promptly rather than after a full
            // interval — supervisor's stop timeout is shorter than most sampling intervals.
            for ($slept = 0; $slept < $interval && $this->running; $slept++) {
                sleep(1);
            }
        } while ($this->running);

        $this->flush();

        return Command::SUCCESS;
    }

    /**
     * A failed sample must never end the collector: supervisorctl can fail transiently while
     * supervisord reloads, and exiting would make this program itself crash-loop — turning the
     * monitor into the outage.
     */
    private function sample(): void
    {
        try {
            $this->reporter->report($this->reader->read());
        } catch (Throwable $e) {
            $this->logger?->warning('Supervisor metrics sample failed.', ['exception' => $e]);

            return;
        }

        $this->flush();
    }

    private function flush(): void
    {
        try {
            $this->metricsFlusher?->flush();
        } catch (Throwable $e) {
            $this->logger?->debug('Supervisor metrics flush failed.', ['error' => $e->getMessage()]);
        }
    }
}
