<?php

declare(strict_types=1);

namespace Vortos\Messaging\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Vortos\Messaging\Contracts\ContractLock;

#[AsCommand(
    name: 'vortos:contracts:check',
    description: 'Diff live wire contracts against contracts.lock; fails on drift. Run in CI.',
)]
final class ContractsCheckCommand extends Command
{
    public function __construct(
        /** @var array<class-string, array{name: string, version: int}> */
        private readonly array $eventWireMap,
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io     = new SymfonyStyle($input, $output);
        $path   = $this->projectDir . '/' . ContractLock::FILENAME;
        $locked = ContractLock::load($path);

        if ($locked === null) {
            $io->warning('No ' . ContractLock::FILENAME . ' found. Run vortos:contracts:lock to create the baseline.');
            return Command::SUCCESS;
        }

        $findings = ContractLock::diff($locked, ContractLock::compute($this->eventWireMap));

        if ($findings === []) {
            $io->success(sprintf('All %d wire contracts match the lockfile.', count($locked)));
            return Command::SUCCESS;
        }

        $io->error(sprintf('%d wire contract drift finding%s:', count($findings), count($findings) === 1 ? '' : 's'));

        foreach ($findings as $finding) {
            $io->writeln('  • ' . $finding);
        }

        return Command::FAILURE;
    }
}
