<?php

declare(strict_types=1);

namespace Vortos\Alerts\Console;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Vortos\Alerts\Notifier\OutboxNotifier;

/** Drains the delivery outbox for every configured channel; also run opportunistically. */
#[AsCommand(
    name: 'vortos:alerts:drain',
    description: 'Drain the alert delivery outbox toward real notifier backends',
)]
final class DrainCommand extends Command
{
    /** @param iterable<OutboxNotifier> $outboxes */
    public function __construct(
        private readonly iterable $outboxes,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $total = 0;

        foreach ($this->outboxes as $outbox) {
            $results = $outbox->drain();
            $total += count($results);
            $io->writeln(sprintf('%s: drained %d', $outbox->name(), count($results)));
        }

        $io->success(sprintf('Drained %d notification(s) total.', $total));

        return Command::SUCCESS;
    }
}
