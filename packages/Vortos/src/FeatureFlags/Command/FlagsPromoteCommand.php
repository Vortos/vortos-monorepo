<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Vortos\FeatureFlags\Application\FlagPromotionService;

#[AsCommand(name: 'vortos:flags:promote', description: 'Promote flag targeting state from one environment to another')]
final class FlagsPromoteCommand extends Command
{
    public function __construct(
        private readonly FlagPromotionService $promotionService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('name',   InputArgument::REQUIRED, 'Flag name')
            ->addArgument('from',   InputArgument::REQUIRED, 'Source environment (e.g. staging)')
            ->addArgument('to',     InputArgument::REQUIRED, 'Target environment (e.g. production)')
            ->addOption('reason',   null, InputOption::VALUE_REQUIRED, 'Reason for promotion')
            ->addOption('force',    null, InputOption::VALUE_NONE, 'Skip confirmation');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name   = (string) $input->getArgument('name');
        $from   = (string) $input->getArgument('from');
        $to     = (string) $input->getArgument('to');
        $reason = $input->getOption('reason');

        if ($from === $to) {
            $output->writeln('<error>Source and target environments must be different.</error>');
            return Command::FAILURE;
        }

        if (!$input->getOption('force')) {
            $output->writeln(sprintf(
                '<comment>Promote "%s" from %s → %s? This will overwrite %s targeting rules. Add --force to confirm.</comment>',
                $name, $from, $to, $to,
            ));
            return Command::SUCCESS;
        }

        try {
            $this->promotionService->promote($name, $from, $to, 'cli', $reason);
        } catch (\RuntimeException $e) {
            $output->writeln(sprintf('<error>%s</error>', $e->getMessage()));
            return Command::FAILURE;
        }

        $output->writeln(sprintf(
            '  <info>promoted:</info> %s  <fg=gray>%s → %s</>',
            $name, $from, $to,
        ));

        return Command::SUCCESS;
    }
}
