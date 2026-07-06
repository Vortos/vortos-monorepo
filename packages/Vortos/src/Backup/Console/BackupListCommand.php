<?php

declare(strict_types=1);

namespace Vortos\Backup\Console;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Vortos\Backup\Catalog\BackupCatalogReadModelInterface;
use Vortos\Backup\Domain\BackupArtifact;
use Vortos\Backup\Domain\DatabaseEngine;
use Vortos\Backup\Environment\DefaultEnvironment;

#[AsCommand(name: 'backup:list', description: 'List cataloged backups for an engine + environment.')]
final class BackupListCommand extends Command
{
    public function __construct(private readonly BackupCatalogReadModelInterface $catalog)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('engine', null, InputOption::VALUE_REQUIRED, 'Database engine: postgres|mongo')
            ->addOption('env', null, InputOption::VALUE_REQUIRED, 'Target environment', DefaultEnvironment::NAME)
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $engine = DatabaseEngine::fromString((string) $input->getOption('engine'));
        $env = (string) $input->getOption('env');

        $artifacts = $this->catalog->list($engine, $env);

        if ($input->getOption('json')) {
            $output->writeln((string) json_encode(
                array_map(static fn (BackupArtifact $a): array => $a->toArray(), $artifacts),
                JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            ));

            return self::SUCCESS;
        }

        if ($artifacts === []) {
            $output->writeln('<comment>No backups found.</comment>');

            return self::SUCCESS;
        }

        foreach ($artifacts as $a) {
            $output->writeln(sprintf(
                '%s  %-13s  %12d bytes  %s',
                $a->createdAt->format('Y-m-d H:i:s'),
                $a->kind->value,
                $a->sizeBytes,
                $a->id->value(),
            ));
        }

        return self::SUCCESS;
    }
}
