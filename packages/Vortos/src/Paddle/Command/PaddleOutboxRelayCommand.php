<?php

declare(strict_types=1);

namespace Vortos\Paddle\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Vortos\Paddle\Outbox\PaddleOutboxRelay;

#[AsCommand(
    name: 'vortos:paddle:outbox:relay',
    description: 'Relay pending Paddle outbox entries to the Paddle API.',
)]
final class PaddleOutboxRelayCommand extends Command
{
    public function __construct(private readonly PaddleOutboxRelay $relay)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $delivered = $this->relay->relay();
        $output->writeln(sprintf('Relayed %d Paddle outbox entr%s.', $delivered, $delivered === 1 ? 'y' : 'ies'));
        return Command::SUCCESS;
    }
}
