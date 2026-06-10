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
    name: 'vortos:contracts:lock',
    description: 'Snapshot all published wire contracts (name, version, schema) into contracts.lock.',
)]
final class ContractsLockCommand extends Command
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
        $io        = new SymfonyStyle($input, $output);
        $contracts = ContractLock::compute($this->eventWireMap);
        $path      = $this->projectDir . '/' . ContractLock::FILENAME;

        ContractLock::write($path, $contracts);

        $io->success(sprintf(
            'Locked %d wire contract%s to %s — commit this file.',
            count($contracts),
            count($contracts) === 1 ? '' : 's',
            ContractLock::FILENAME,
        ));

        foreach ($contracts as $name => $contract) {
            $io->writeln(sprintf('  <info>%s</info>.v%d ← %s', $name, $contract['version'], $contract['class']));
        }

        return Command::SUCCESS;
    }
}
