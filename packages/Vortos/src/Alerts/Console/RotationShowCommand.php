<?php

declare(strict_types=1);

namespace Vortos\Alerts\Console;

use DateTimeImmutable;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Vortos\Alerts\Escalation\OnCallRotation;

/** Inspects the current on-call rotation. */
#[AsCommand(
    name: 'vortos:alerts:rotation:show',
    description: 'Show who is currently on call',
)]
final class RotationShowCommand extends Command
{
    public function __construct(
        private readonly OnCallRotation $rotation,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $now = new DateTimeImmutable();
        $current = $this->rotation->currentResponder($now);

        $io->writeln(sprintf('Currently on call: %s (%s) via %s', $current->name, $current->id, $current->channelKey));

        return Command::SUCCESS;
    }
}
