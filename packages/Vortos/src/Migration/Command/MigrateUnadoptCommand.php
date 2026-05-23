<?php

declare(strict_types=1);

namespace Vortos\Migration\Command;

use Doctrine\Migrations\Exception\NoMigrationsFoundWithCriteria;
use Doctrine\Migrations\Version\Direction;
use Doctrine\Migrations\Version\ExecutionResult;
use Doctrine\Migrations\Version\Version;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Vortos\Migration\Service\DependencyFactoryProviderInterface;
use Vortos\Migration\Service\ModuleMigrationRegistryInterface;

#[AsCommand(
    name: 'vortos:migrate:unadopt',
    description: 'Remove a migration tracking record without touching the schema',
)]
final class MigrateUnadoptCommand extends Command
{
    public function __construct(
        private readonly DependencyFactoryProviderInterface $factoryProvider,
        private readonly ModuleMigrationRegistryInterface $moduleRegistry,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('version', InputArgument::OPTIONAL, 'Migration version to unadopt (omit to unadopt the latest)')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Skip confirmation prompt');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $factory = $this->factoryProvider->create();
        $storage = $factory->getMetadataStorage();
        $storage->ensureInitialized();

        $executed = $storage->getExecutedMigrations();
        $versionInput = (string) ($input->getArgument('version') ?? '');

        if ($versionInput !== '') {
            $targetVersion = $this->resolveVersion($versionInput, $executed->getItems());

            if ($targetVersion === null) {
                $output->writeln(sprintf('<error>Migration "%s" is not recorded as executed.</error>', $versionInput));
                return Command::FAILURE;
            }
        } else {
            try {
                $targetVersion = (string) $executed->getLast()->getVersion();
            } catch (NoMigrationsFoundWithCriteria) {
                $output->writeln('<error>No executed migrations found to unadopt.</error>');
                return Command::FAILURE;
            }
        }

        $isFramework = $this->moduleRegistry->descriptorForClass($targetVersion) !== null;

        if ($isFramework) {
            $output->writeln(sprintf(
                '<comment>Warning: "%s" is a framework module migration. Unadopting it may cause migrate to re-apply framework schema.</comment>',
                $targetVersion,
            ));
        }

        $output->writeln('');
        $output->writeln('  About to unadopt:');
        $output->writeln(sprintf('    <info>%s</info>', $targetVersion));
        $output->writeln('');
        $output->writeln('  This will <comment>NOT</comment> touch your schema — only removes the tracking record.');
        $output->writeln('');

        if (!(bool) $input->getOption('force') && $input->isInteractive()) {
            /** @var QuestionHelper $helper */
            $helper = $this->getHelper('question');

            if (!$helper->ask($input, $output, new ConfirmationQuestion('  Confirm? [y/N] ', false))) {
                $output->writeln('<comment>Unadopt aborted.</comment>');
                return Command::SUCCESS;
            }
        }

        $result = new ExecutionResult(new Version($targetVersion), Direction::DOWN);
        $storage->complete($result);

        $output->writeln(sprintf('<info>✔ Unadopted %s.</info>', $targetVersion));
        $output->writeln('');
        $output->writeln('  Next steps:');
        $output->writeln(sprintf('    Run migration:  <comment>php vortos migrate</comment>'));
        $output->writeln(sprintf('    Re-adopt:       <comment>php vortos migrate:adopt %s</comment>', $targetVersion));

        return Command::SUCCESS;
    }

    /**
     * @param list<\Doctrine\Migrations\Metadata\ExecutedMigration> $items
     */
    private function resolveVersion(string $input, array $items): ?string
    {
        foreach ($items as $migration) {
            $version = (string) $migration->getVersion();

            if ($version === $input
                || str_ends_with($version, '\\' . $input)
                || basename(str_replace('\\', '/', $version)) === $input
            ) {
                return $version;
            }
        }

        return null;
    }
}
