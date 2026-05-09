<?php

declare(strict_types=1);

namespace Vortos\Messaging\Command;

use Vortos\Messaging\Runtime\ConsumerRunner;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * CLI command to start a consumer worker process.
 * 
 * Usage:
 *  php bin/console vortos:consume orders.placed
 *  php bin/console vortos:consume orders.placed --timeout=60
 * 
 * Each invocation runs one worker. For parallelism, run multiple
 * processes with the same consumer name — Kafka handles partition
 * distribution via the consumer group ID.
 * 
 * Handles SIGTERM and SIGINT for graceful shutdown if pcntl is available.
 */
#[AsCommand(
    name: 'vortos:consume',
    description: 'Start a consumer worker for a named consumer pipeline'
)]
final class ConsumeCommand extends Command
{
    public function __construct(
        private ConsumerRunner $consumerRunner,
        private LoggerInterface $logger
    )
    {
        parent::__construct();
    }

    public function configure():void
    {
        $this->addArgument('consumer', InputArgument::REQUIRED, 'The consumer name to run')
            ->addOption('timeout', 't', InputOption::VALUE_OPTIONAL, 'Stop after N seconds (0 = run forever)', 0)
            ->addOption('max-messages', null, InputOption::VALUE_OPTIONAL, 'Stop after processing N messages (0 = unlimited)', 0);
    }

    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $consumerName = $input->getArgument('consumer');

        $timeout     = (int) $input->getOption('timeout');
        $maxMessages = (int) $input->getOption('max-messages');

        if ($timeout > 0) {
            if (extension_loaded('pcntl')) {
                pcntl_signal(SIGALRM, fn() => $this->consumerRunner->stop());
                pcntl_alarm($timeout);
                $output->writeln("<comment>Timeout set to {$timeout}s</comment>");
            } else {
                $output->writeln('<comment>Warning: pcntl not available, timeout option ignored</comment>');
            }
        }

        $output->writeln("<info>Starting consumer '{$consumerName}'...</info>");

        if (extension_loaded('pcntl')) {
            pcntl_signal(SIGTERM, fn() => $this->consumerRunner->stop());
            pcntl_signal(SIGINT, fn() => $this->consumerRunner->stop());
        }

        try {
            $this->consumerRunner->run($consumerName, $maxMessages);
        } catch (\Throwable $e) {
            
            $this->logger->error(
                'Consumer failed', 
                [
                    'consumer' => $consumerName, 
                    'exception' => $e->getMessage()
                ]
            );

            $output->writeln("<error>Consumer failed: {$e->getMessage()}</error>");
    
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}