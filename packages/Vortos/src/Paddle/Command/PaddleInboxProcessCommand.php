<?php

declare(strict_types=1);

namespace Vortos\Paddle\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Vortos\Paddle\Inbox\PaddleInboxWorkerInterface;

#[AsCommand(
    name: 'vortos:paddle:inbox:process',
    description: 'Process pending Paddle webhook inbox rows through their handlers.',
)]
final class PaddleInboxProcessCommand extends Command
{
    private bool $shouldStop = false;

    public function __construct(
        private readonly PaddleInboxWorkerInterface $worker,
        private readonly int $sleepSecondsWhenEmpty = 2,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('once', null, InputOption::VALUE_NONE, 'Process one batch then exit.')
            ->addOption('sleep', null, InputOption::VALUE_REQUIRED, 'Seconds to sleep between empty polls.', $this->sleepSecondsWhenEmpty);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io           = new SymfonyStyle($input, $output);
        $once         = (bool) $input->getOption('once');
        $sleepSeconds = max(0, (int) $input->getOption('sleep'));
        $total        = 0;

        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGTERM, fn() => $this->shouldStop = true);
            pcntl_signal(SIGINT,  fn() => $this->shouldStop = true);
        }

        do {
            if (function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }

            $processed = $this->worker->process();
            $total    += $processed;

            if ($processed === 0 && !$once && !$this->shouldStop && $sleepSeconds > 0) {
                sleep($sleepSeconds);
            }
        } while (!$once && !$this->shouldStop);

        $io->success(sprintf('Processed %d Paddle webhook inbox row%s.', $total, $total === 1 ? '' : 's'));

        return Command::SUCCESS;
    }
}
