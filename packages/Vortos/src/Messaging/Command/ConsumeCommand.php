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
    description: 'Start a consumer worker for a named consumer pipeline'
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
            ->addArgument('consumer', InputArgument::REQUIRED, 'The consumer name to run')
            ->addOption('timeout', 't', InputOption::VALUE_OPTIONAL, 'Stop after N seconds (0 = run forever)', 0)
            ->addOption('max-messages', null, InputOption::VALUE_OPTIONAL, 'Stop after processing N messages (0 = unlimited)', 0)
            ->addOption('tail', null, InputOption::VALUE_NONE, 'Print live message activity to the terminal (dev only)');
    }

    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $consumerName = $input->getArgument('consumer');
        $timeout      = max(0, min((int) $input->getOption('timeout'), 86400));
        $maxMessages  = max(0, min((int) $input->getOption('max-messages'), 1_000_000));

        if (extension_loaded('pcntl')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM, fn() => $this->consumerRunner->stop());
            pcntl_signal(SIGINT, fn() => $this->consumerRunner->stop());

            if ($timeout > 0) {
                pcntl_signal(SIGALRM, fn() => $this->consumerRunner->stop());
                pcntl_alarm($timeout);
                $output->writeln("<comment>Timeout set to {$timeout}s</comment>");
            }
        } elseif ($timeout > 0) {
            $output->writeln('<comment>Warning: pcntl not available, timeout option ignored</comment>');
        }

        if ($this->tailState !== null && $input->getOption('tail')) {
            $this->tailState->activate(new ConsoleTailChannel(new TailRenderer($output)));
            $output->writeln(sprintf('<fg=gray>Tailing consumer:</> <info>%s</info>', $consumerName));
            $output->writeln('<fg=gray>Live message activity — Ctrl+C to stop.</>');
            $output->writeln('');
        } else {
            $output->writeln("<info>Starting consumer '{$consumerName}'...</info>");
        }

        try {
            $this->consumerRunner->run($consumerName, $maxMessages);
        } catch (\Throwable $e) {
            $this->logger->error('Consumer failed', ['consumer' => $consumerName, 'exception' => $e->getMessage()]);
            $output->writeln("<error>Consumer '{$consumerName}' failed: {$e->getMessage()}</error>");

            return Command::FAILURE;
        } finally {
            $this->tailState?->streamEnd();
        }

        return Command::SUCCESS;
    }
}
