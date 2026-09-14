<?php

declare(strict_types=1);

namespace Vortos\Messaging\Command;

use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Vortos\Messaging\Dev\Channel\ConsoleTailChannel;
use Vortos\Messaging\Dev\TailRenderer;
use Vortos\Messaging\Dev\TailState;
use Vortos\Messaging\Runtime\ConsumerRunnerInterface;

#[AsCommand(
    name: 'vortos:consume',
    description: 'Start a consumer worker for one or more named consumer pipelines'
)]
final class ConsumeCommand extends Command
{
    public function __construct(
        private readonly ConsumerRunnerInterface $consumerRunner,
        private readonly LoggerInterface $logger,
        private readonly ?TailState $tailState = null,
    ) {
        parent::__construct();
    }

    public function configure(): void
    {
        $this
            ->addArgument(
                'consumer',
                InputArgument::REQUIRED | InputArgument::IS_ARRAY,
                'One or more consumer names to run in this process. Several names share the process, never a consumer group.',
            )
            ->addOption('timeout', 't', InputOption::VALUE_OPTIONAL, 'Stop after N seconds (0 = run forever)', 0)
            ->addOption('max-messages', null, InputOption::VALUE_OPTIONAL, 'Stop after processing N messages (0 = unlimited)', 0)
            ->addOption('max-memory-mb', null, InputOption::VALUE_OPTIONAL, 'Stop gracefully once the process holds this many MiB, so supervisor restarts it (0 = no cap)', 0)
            ->addOption('tail', null, InputOption::VALUE_NONE, 'Print live message activity to the terminal (dev only)')
            ->addOption('drain-deadline', null, InputOption::VALUE_OPTIONAL, 'Drain deadline in seconds (force-exit on overrun)', null);
    }

    public function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var list<string> $consumerNames */
        $consumerNames = array_values(array_unique(array_map('strval', (array) $input->getArgument('consumer'))));
        $label         = implode(', ', $consumerNames);
        $timeout       = max(0, min((int) $input->getOption('timeout'), 86400));
        $maxMessages   = max(0, min((int) $input->getOption('max-messages'), 1_000_000));
        $maxMemoryMb   = max(0, min((int) $input->getOption('max-memory-mb'), 65_536));

        $drainDeadline = $input->getOption('drain-deadline');
        if ($drainDeadline === null) {
            $drainDeadline = (int) (getenv('VORTOS_DRAIN_DEADLINE') ?: 25);
        } else {
            $drainDeadline = max(1, min((int) $drainDeadline, 3600));
        }

        if (extension_loaded('pcntl')) {
            pcntl_async_signals(true);

            pcntl_signal(SIGTERM, function () use ($output, $drainDeadline): void {
                $this->consumerRunner->stop();
                if ($drainDeadline > 0) {
                    pcntl_alarm($drainDeadline);
                    $output->writeln(sprintf('<comment>SIGTERM received — draining (deadline %ds)</comment>', $drainDeadline));
                }
            });
            pcntl_signal(SIGINT, fn() => $this->consumerRunner->stop());

            pcntl_signal(SIGALRM, static function () use ($output): void {
                $output->writeln('<error>Drain deadline exceeded — force-exiting (message will be redelivered safely via inbox)</error>');
                exit(143);
            });

            if ($timeout > 0) {
                pcntl_alarm($timeout);
                $output->writeln("<comment>Timeout set to {$timeout}s</comment>");
            }
        } elseif ($timeout > 0) {
            $output->writeln('<comment>Warning: pcntl not available, timeout option ignored</comment>');
        }

        if ($this->tailState !== null && $input->getOption('tail')) {
            $this->tailState->activate(new ConsoleTailChannel(new TailRenderer($output)));
            $output->writeln(sprintf('<fg=gray>Tailing consumer:</> <info>%s</info>', $label));
            $output->writeln('<fg=gray>Live message activity — Ctrl+C to stop.</>');
            $output->writeln('');
        } else {
            $output->writeln("<info>Starting consumer '{$label}'...</info>");
        }

        try {
            // One consumer with no memory cap runs exactly as it always has, on the consumer's own loop.
            if (count($consumerNames) === 1 && $maxMemoryMb === 0) {
                $this->consumerRunner->run($consumerNames[0], $maxMessages);
            } else {
                $this->consumerRunner->runMany($consumerNames, $maxMessages, $maxMemoryMb * 1024 * 1024);
            }
        } catch (\Throwable $e) {
            $this->logger->error('Consumer failed', ['consumer' => $label, 'exception' => $e->getMessage()]);
            $output->writeln("<error>Consumer '{$label}' failed: {$e->getMessage()}</error>");

            return Command::FAILURE;
        } finally {
            $this->tailState?->streamEnd();
        }

        return Command::SUCCESS;
    }
}
