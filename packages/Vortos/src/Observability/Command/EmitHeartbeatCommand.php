<?php

declare(strict_types=1);

namespace Vortos\Observability\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Vortos\Observability\Heartbeat\HeartbeatEmitterInterface;
use Vortos\Observability\Heartbeat\HeartbeatPing;
use Vortos\Observability\Heartbeat\HeartbeatStatus;

/**
 * Emits one dead-man's-switch check-in to the external monitor. Run from cron / a
 * single worker per environment (e.g. every 60s). Exits non-zero when the monitor did
 * not acknowledge, so the scheduler records the miss; *absence* of check-ins is what
 * pages, detected off-host.
 */
#[AsCommand(
    name: 'vortos:observability:heartbeat',
    description: 'Emit a dead-man heartbeat check-in to the external monitor',
)]
final class EmitHeartbeatCommand extends Command
{
    public function __construct(
        private readonly HeartbeatEmitterInterface $emitter,
        private readonly string $defaultMonitorKey,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('monitor', 'm', InputOption::VALUE_REQUIRED, 'Monitor key', $this->defaultMonitorKey)
            ->addOption('status', 's', InputOption::VALUE_REQUIRED, 'start|success|fail', 'success')
            ->addOption('note', null, InputOption::VALUE_REQUIRED, 'Optional short note');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $status = HeartbeatStatus::tryFrom((string) $input->getOption('status'));
        if ($status === null) {
            $io->error('Invalid --status; expected start, success, or fail.');

            return Command::FAILURE;
        }

        $note = $input->getOption('note');
        $ping = HeartbeatPing::create(
            (string) $input->getOption('monitor'),
            $status,
            is_string($note) ? $note : null,
        );

        if (!$this->emitter->emit($ping)) {
            $io->warning('Heartbeat not acknowledged (monitor unreachable or not configured).');

            return Command::FAILURE;
        }

        $io->success('Heartbeat sent.');

        return Command::SUCCESS;
    }
}
