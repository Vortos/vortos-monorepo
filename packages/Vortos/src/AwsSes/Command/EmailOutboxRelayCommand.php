<?php

declare(strict_types=1);

namespace Vortos\AwsSes\Command;

use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Vortos\AwsSes\Outbox\EmailOutboxRelay;

/**
 * Long-running outbox relay worker.
 *
 * Polls the aws_ses_outbox table for pending rows and delivers them via the
 * configured mailer. Runs until interrupted (SIGTERM / Ctrl+C) or until
 * --once is passed for one-shot usage.
 *
 * Usage:
 *   bin/console vortos:ses:outbox:relay
 *   bin/console vortos:ses:outbox:relay --once
 *   bin/console vortos:ses:outbox:relay --sleep=2
 */
#[AsCommand(
    name:        'vortos:ses:outbox:relay',
    description: 'Run the SES outbox relay worker — pick up pending emails and send them.',
)]
final class EmailOutboxRelayCommand extends Command
{
    private bool $shouldStop = false;

    public function __construct(
        private readonly EmailOutboxRelay $relay,
        private readonly LoggerInterface $logger,
        private readonly int $defaultSleepSeconds,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('once',  null, InputOption::VALUE_NONE,     'Process one batch then exit.')
            ->addOption('sleep', null, InputOption::VALUE_REQUIRED, 'Seconds to sleep between empty polls.', $this->defaultSleepSeconds);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io         = new SymfonyStyle($input, $output);
        $once       = (bool) $input->getOption('once');
        $sleepSec   = (int)  $input->getOption('sleep');

        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGTERM, fn() => $this->shouldStop = true);
            pcntl_signal(SIGINT,  fn() => $this->shouldStop = true);
        }

        $io->title('SES Outbox Relay');

        do {
            if (function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }

            try {
                $sent = $this->relay->relay();

                if ($sent > 0) {
                    $io->writeln(sprintf('<info>Sent %d email(s)</info>', $sent));
                } elseif ($output->isVerbose()) {
                    $io->writeln('No pending emails — sleeping.');
                }

                if ($sent === 0 && !$once) {
                    sleep($sleepSec);
                }
            } catch (\Throwable $e) {
                $this->logger->error('ses.outbox.relay: unexpected error', ['error' => $e->getMessage()]);
                $io->error($e->getMessage());

                if (!$once) {
                    sleep($sleepSec);
                }
            }
        } while (!$once && !$this->shouldStop);

        return Command::SUCCESS;
    }
}
