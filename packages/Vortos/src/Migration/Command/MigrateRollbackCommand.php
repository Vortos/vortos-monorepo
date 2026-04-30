<?php

declare(strict_types=1);

namespace Vortos\Migration\Command;

use Doctrine\Migrations\MigratorConfiguration;
use Doctrine\Migrations\Version\Version;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Vortos\Migration\Service\DependencyFactoryProvider;

/**
 * Rolls back the last N executed migrations.
 *
 * ## Usage
 *
 *   php bin/console vortos:migrate:rollback
 *   php bin/console vortos:migrate:rollback --steps=3
 *   php bin/console vortos:migrate:rollback --all
 *
 * ## Safety
 *
 * Requires interactive confirmation unless --no-interaction is supplied.
 * Uses all-or-nothing semantics: if any down() fails, the entire rollback
 * is rolled back (the original state is preserved).
 *
 * Migrations generated from SQL stubs throw IrreversibleMigrationException
 * in their down() — these cannot be rolled back and will abort the command.
 */
#[AsCommand(
    name: 'vortos:migrate:rollback',
    description: 'Undo the last N executed migrations',
)]
final class MigrateRollbackCommand extends Command
{
    public function __construct(private readonly DependencyFactoryProvider $factoryProvider)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('steps', 's', InputOption::VALUE_REQUIRED, 'Number of migrations to roll back', '1')
            ->addOption('all', null, InputOption::VALUE_NONE, 'Roll back all executed migrations');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $factory = $this->factoryProvider->create();
        $storage = $factory->getMetadataStorage();
        $storage->ensureInitialized();

        $executed = $storage->getExecutedMigrations();

        if ($executed->count() === 0) {
            $output->writeln('<info>No migrations to roll back.</info>');
            return Command::SUCCESS;
        }

        $steps = $input->getOption('all')
            ? $executed->count()
            : max(1, (int) $input->getOption('steps'));

        $targetVersion = $this->resolveTarget($executed->getItems(), $steps);
        $plan          = $factory->getMigrationPlanCalculator()->getPlanUntilVersion($targetVersion);

        if (count($plan) === 0) {
            $output->writeln('<info>Nothing to roll back.</info>');
            return Command::SUCCESS;
        }

        $output->writeln(sprintf('<info>%d migration(s) will be rolled back:</info>', count($plan)));

        foreach ($plan->getItems() as $item) {
            $output->writeln(sprintf('  <comment>←</comment> %s', (string) $item->getVersion()));
        }

        $output->writeln('');

        if ($input->isInteractive()) {
            /** @var QuestionHelper $helper */
            $helper = $this->getHelper('question');

            if (!$helper->ask($input, $output, new ConfirmationQuestion('<question>Proceed with rollback? [y/N]</question> ', false))) {
                $output->writeln('<comment>Rollback aborted.</comment>');
                return Command::SUCCESS;
            }
        }

        $factory->getMigrator()->migrate(
            $plan,
            (new MigratorConfiguration())->setAllOrNothing(true),
        );

        $output->writeln(sprintf('<info>✔ Rolled back %d migration(s).</info>', count($plan)));

        return Command::SUCCESS;
    }

    /**
     * @param array<string, \Doctrine\Migrations\Metadata\ExecutedMigration> $executedItems
     */
    private function resolveTarget(array $executedItems, int $steps): Version
    {
        $versions = array_keys($executedItems);
        sort($versions);

        $count = count($versions);

        if ($steps >= $count) {
            return new Version('0');
        }

        return new Version($versions[$count - $steps - 1]);
    }
}
