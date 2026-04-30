<?php

declare(strict_types=1);

namespace Vortos\Migration\Command;

use Doctrine\Migrations\Version\Direction;
use Doctrine\Migrations\Version\ExecutionResult;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Vortos\Migration\Service\DependencyFactoryProvider;

/**
 * Marks all available migrations as already executed without running them.
 *
 * ## When to use
 *
 * Use this once when transitioning from manually-applied SQL to Doctrine tracking.
 * If the database schema was built by running raw psql scripts (as was the case
 * before this migration system existed), the tables are already in place.
 * Baseline tells Doctrine "all these are done" so vortos:migrate only picks up
 * genuinely new migrations going forward.
 *
 * ## Usage
 *
 *   php bin/console vortos:migrate:baseline
 *
 * ## Idempotent
 *
 * Already-tracked migrations are skipped. Safe to run more than once.
 */
#[AsCommand(
    name: 'vortos:migrate:baseline',
    description: 'Mark all available migrations as already executed (for transitioning from manual SQL)',
)]
final class MigrateBaselineCommand extends Command
{
    public function __construct(private readonly DependencyFactoryProvider $factoryProvider)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('force', null, InputOption::VALUE_NONE, 'Skip confirmation prompt');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $factory  = $this->factoryProvider->create();
        $storage  = $factory->getMetadataStorage();
        $storage->ensureInitialized();

        $available = $factory->getMigrationPlanCalculator()->getMigrations();
        $executed  = $storage->getExecutedMigrations();

        $pending = array_filter(
            $available->getItems(),
            static fn($m) => !$executed->hasMigration($m->getVersion()),
        );

        if (empty($pending)) {
            $output->writeln('<info>All migrations are already tracked. Nothing to baseline.</info>');
            return Command::SUCCESS;
        }

        $output->writeln(sprintf('<info>%d migration(s) will be marked as executed:</info>', count($pending)));

        foreach ($pending as $migration) {
            $output->writeln(sprintf('  <comment>→</comment> %s', (string) $migration->getVersion()));
        }

        $output->writeln('');

        $force = (bool) $input->getOption('force');

        if (!$force && $input->isInteractive()) {
            /** @var QuestionHelper $helper */
            $helper = $this->getHelper('question');

            if (!$helper->ask($input, $output, new ConfirmationQuestion('<question>Mark these as executed? [y/N]</question> ', false))) {
                $output->writeln('<comment>Baseline aborted.</comment>');
                return Command::SUCCESS;
            }
        }

        $now   = new \DateTimeImmutable();
        $count = 0;

        foreach ($pending as $migration) {
            $result = new ExecutionResult($migration->getVersion(), Direction::UP);
            $result->setExecutedAt($now);
            $storage->complete($result);

            $output->writeln(sprintf('  <info>✔ Marked:</info> %s', (string) $migration->getVersion()));
            $count++;
        }

        $output->writeln('');
        $output->writeln(sprintf('<info>✔ Baselined %d migration(s).</info>', $count));

        return Command::SUCCESS;
    }
}
